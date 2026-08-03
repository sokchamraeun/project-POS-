<?php
require 'auth.php';
require 'config.php';
if (!can('ingredients')) { header("Location: dashboard.php?denied=1"); exit; }

/* ══════════════════════════════════════════
   AJAX: Quick Restock (with optional note)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_restock') {
    header('Content-Type: application/json');
    $iid  = (int)($_POST['ingredient_id'] ?? 0);
    $amt  = (float)($_POST['amount'] ?? 0);
    $note = trim($_POST['note'] ?? '');
    if ($iid <= 0 || $amt <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }
    $conn->begin_transaction();
    try {
        $s1 = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?");
        $s1->bind_param("di", $amt, $iid);
        $s1->execute();
        $logNote = $note ?: "Quick restock: +$amt";
        if ($conn->query("SHOW TABLES LIKE 'stock_refills'")->num_rows > 0) {
            $s2 = $conn->prepare("INSERT INTO stock_refills (ingredient_id, purchase_qty, notes) VALUES (?, ?, ?)");
            $s2->bind_param("ids", $iid, $amt, $logNote);
            $s2->execute();
        }
        $by = $_SESSION['username'] ?? null;
        $sh = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, reference, created_by) VALUES (?, 'quick_restock', ?, ?, ?)");
        $sh->bind_param("idss", $iid, $amt, $logNote, $by);
        $sh->execute();
        $conn->commit();
        $s3 = $conn->prepare("SELECT stock_quantity, minimum_stock FROM ingredients WHERE ingredient_id = ?");
        $s3->bind_param("i", $iid);
        $s3->execute();
        $r = $s3->get_result()->fetch_assoc();
        echo json_encode(['success'=>true,'new_stock'=>(float)$r['stock_quantity'],'min_stock'=>(float)$r['minimum_stock']]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Update Minimum Stock (inline edit)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_min') {
    header('Content-Type: application/json');
    $iid = (int)($_POST['ingredient_id'] ?? 0);
    $min = (float)($_POST['minimum_stock'] ?? -1);
    if ($iid <= 0 || $min < 0) { echo json_encode(['success'=>false,'message'=>'Invalid']); exit; }
    $s = $conn->prepare("UPDATE ingredients SET minimum_stock = ? WHERE ingredient_id = ?");
    $s->bind_param("di", $min, $iid);
    $s->execute();
    echo json_encode(['success' => true]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Delete Ingredient
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ingredient') {
    header('Content-Type: application/json');
    $iid = (int)($_POST['ingredient_id'] ?? 0);
    if ($iid <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
    if ($conn->query("SHOW TABLES LIKE 'product_ingredients'")->num_rows > 0) {
        $chk = $conn->prepare("SELECT COUNT(*) AS cnt FROM product_ingredients WHERE ingredient_id = ?");
        $chk->bind_param("i", $iid);
        $chk->execute();
        $cnt = $chk->get_result()->fetch_assoc()['cnt'];
        if ($cnt > 0) { echo json_encode(['success'=>false,'message'=>"Used in $cnt recipe(s) — remove from recipes first"]); exit; }
    }
    if ($conn->query("SHOW TABLES LIKE 'purchase_order_items'")->num_rows > 0) {
        $chk2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM purchase_order_items WHERE ingredient_id = ?");
        $chk2->bind_param("i", $iid);
        $chk2->execute();
        $cnt2 = $chk2->get_result()->fetch_assoc()['cnt'];
        if ($cnt2 > 0) { echo json_encode(['success'=>false,'message'=>"Linked to $cnt2 purchase order item(s) — cannot delete"]); exit; }
    }
    $del = $conn->prepare("DELETE FROM ingredients WHERE ingredient_id = ?");
    $del->bind_param("i", $iid);
    $del->execute();
    echo json_encode(['success' => $del->affected_rows > 0, 'message' => $del->affected_rows > 0 ? '' : 'Delete failed']);
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Manual Stock Adjustment
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual_adjust') {
    header('Content-Type: application/json');
    $iid      = (int)($_POST['ingredient_id'] ?? 0);
    $delta    = (float)($_POST['delta'] ?? 0);
    $reason   = trim($_POST['reason'] ?? '');
    $category = trim($_POST['reason_category'] ?? 'other');

    if ($iid <= 0 || $delta == 0) { echo json_encode(['success'=>false,'message'=>'Invalid input']); exit; }
    if (empty($reason))           { echo json_encode(['success'=>false,'message'=>'Reason is required']); exit; }

    if (!in_array($category, ['spoilage','spillage','stock_discrepancy','supplier_return','restock','other'])) {
        $category = 'other';
    }

    $conn->begin_transaction();
    try {
        $chk = $conn->prepare("SELECT ingredient_name, stock_quantity, minimum_stock, unit FROM ingredients WHERE ingredient_id = ?");
        $chk->bind_param("i", $iid);
        $chk->execute();
        $cur = $chk->get_result()->fetch_assoc();
        $curStock = (float)$cur['stock_quantity'];
        $newStock = $curStock + $delta;
        if ($newStock < 0) {
            $conn->rollback();
            echo json_encode(['success'=>false,'message'=>"Cannot remove more than current stock (".rtrim(rtrim(number_format($curStock,4,'.','' ),'0'),'.').")"]);
            exit;
        }
        $s1 = $conn->prepare("UPDATE ingredients SET stock_quantity = stock_quantity + ? WHERE ingredient_id = ?");
        $s1->bind_param("di", $delta, $iid);
        $s1->execute();
        $by = $_SESSION['username'] ?? null;
        $sh = $conn->prepare("INSERT INTO ingredient_history (ingredient_id, change_type, amount, reference, reason_category, reviewed, created_by) VALUES (?, 'manual_adjust', ?, ?, ?, 0, ?)");
        $sh->bind_param("idsss", $iid, $delta, $reason, $category, $by);
        $sh->execute();
        $history_id = $conn->insert_id;

        // Trigger Notification Bell alert for Loss / High-Risk events
        if (in_array($category, ['spoilage', 'spillage', 'stock_discrepancy', 'other']) || $delta < 0) {
            $ingName  = $cur['ingredient_name'] ?? 'Ingredient';
            $catMap   = [
                'spoilage'          => 'Spoilage / Expired',
                'spillage'          => 'Spill / Waste',
                'stock_discrepancy' => 'Stock Count Discrepancy',
                'supplier_return'   => 'Supplier Return',
                'restock'           => 'Quick Restock',
                'other'             => 'Manual Adjustment'
            ];
            $catLabel  = $catMap[$category] ?? 'Manual Adjustment';
            $notifType = ($category === 'spoilage') ? 'danger' : 'warning';
            $title     = "Inventory Alert: " . $ingName;
            $deltaText = ($delta > 0 ? "+$delta" : "$delta") . " " . ($cur['unit'] ?? '');
            $msg       = ($by ?: 'Staff') . " logged " . $catLabel . " (" . $deltaText . "). Reason: \"" . $reason . "\".";

            $anc = $conn->prepare("INSERT INTO announcements (title, message, type, is_active, created_at) VALUES (?, ?, ?, 1, NOW())");
            if ($anc) {
                $anc->bind_param("sss", $title, $msg, $notifType);
                $anc->execute();
            }
        }

        $conn->commit();
        $s3 = $conn->prepare("SELECT stock_quantity, minimum_stock FROM ingredients WHERE ingredient_id = ?");
        $s3->bind_param("i", $iid);
        $s3->execute();
        $rr = $s3->get_result()->fetch_assoc();
        echo json_encode(['success'=>true,'new_stock'=>(float)$rr['stock_quantity'],'min_stock'=>(float)$rr['minimum_stock']]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Get Unreviewed Stock Adjustments
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_unreviewed') {
    header('Content-Type: application/json');
    $is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']) || !empty($_SESSION['is_admin']) || can('manage_roles') || can('report');
    if (!$is_mgr) {
        echo json_encode(['ok' => true, 'unreviewed_count' => 0, 'items' => []]);
        exit;
    }
    $res = $conn->query("
        SELECT h.id AS history_id, h.ingredient_id, h.amount, h.reference, h.reason_category, h.reviewed, h.created_by, h.created_at,
               i.ingredient_name, i.unit
        FROM ingredient_history h
        JOIN ingredients i ON i.ingredient_id = h.ingredient_id
        WHERE h.reviewed = 0 AND h.change_type IN ('manual_adjust', 'quick_restock')
        ORDER BY h.created_at DESC
        LIMIT 50
    ");
    $items = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'history_id'       => (int)$r['history_id'],
                'ingredient_id'    => (int)$r['ingredient_id'],
                'ingredient_name'  => $r['ingredient_name'],
                'unit'             => $r['unit'],
                'amount'           => (float)$r['amount'],
                'reference'        => $r['reference'] ?? '',
                'reason_category'  => $r['reason_category'] ?? 'other',
                'created_by'       => $r['created_by'] ?? 'Unknown',
                'created_at'       => $r['created_at']
            ];
        }
    }
    echo json_encode(['ok' => true, 'unreviewed_count' => count($items), 'items' => $items]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Mark Stock Adjustment History Reviewed
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_reviewed') {
    header('Content-Type: application/json');
    $is_mgr = in_array($_SESSION['role'] ?? '', ['admin', 'manager']) || !empty($_SESSION['is_admin']) || can('manage_roles') || can('report');
    if (!$is_mgr) { echo json_encode(['ok' => false, 'message' => 'Permission denied: Manager review required']); exit; }

    $hid = (int)($_POST['history_id'] ?? 0);
    $markAll = (int)($_POST['all'] ?? 0);
    $reviewer = $_SESSION['username'] ?? 'Manager';

    if ($markAll === 1) {
        $u = $conn->prepare("UPDATE ingredient_history SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW() WHERE reviewed = 0 AND change_type IN ('manual_adjust', 'quick_restock')");
        $u->bind_param("s", $reviewer);
        $u->execute();
        $count = $u->affected_rows;
    } else if ($hid > 0) {
        $u = $conn->prepare("UPDATE ingredient_history SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $u->bind_param("si", $reviewer, $hid);
        $u->execute();
        $count = $u->affected_rows;
    } else {
        echo json_encode(['ok' => false, 'message' => 'Invalid history ID']);
        exit;
    }

    echo json_encode(['ok' => true, 'updated_count' => $count]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Poll (real-time stock sync)
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'poll') {
    header('Content-Type: application/json');
    $fc_map = [];
    $fc_res = $conn->query("SELECT ingredient_id, SUM(amount)/7 AS daily_avg FROM ingredient_history WHERE change_type='order_deduct' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY ingredient_id");
    if ($fc_res) while ($fc = $fc_res->fetch_assoc()) $fc_map[(int)$fc['ingredient_id']] = (float)$fc['daily_avg'];
    $out = [];
    $res = $conn->query("SELECT ingredient_id, ingredient_name, stock_quantity, minimum_stock, cost_per_unit, unit, supplier_id FROM ingredients ORDER BY ingredient_name ASC");
    while ($r = $res->fetch_assoc()) {
        $id = (int)$r['ingredient_id'];
        $out[] = ['id'=>$id,'stock'=>(float)$r['stock_quantity'],'min'=>(float)$r['minimum_stock'],'cpu'=>(float)$r['cost_per_unit'],'unit'=>$r['unit']??'','supplier_id'=>(int)($r['supplier_id']??0),'daily_avg'=>$fc_map[$id]??0];
    }
    echo json_encode(['ok'=>true,'ingredients'=>$out]);
    exit;
}

/* ══════════════════════════════════════════
   AJAX: Ingredient History
══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'history') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok'=>false]); exit; }
    $out = [];
    $r = $conn->prepare("SELECT change_type, amount, reference, created_by, created_at FROM ingredient_history WHERE ingredient_id = ? ORDER BY created_at DESC LIMIT 30");
    $r->bind_param('i', $id);
    $r->execute();
    $res = $r->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[] = ['type'=>$row['change_type'],'amount'=>(float)$row['amount'],'reference'=>$row['reference']??'','by'=>$row['created_by']??'','at'=>$row['created_at']];
    }
    echo json_encode(['ok'=>true,'history'=>$out]);
    exit;
}

/* ══════════════════════════════════════════
   DATA
══════════════════════════════════════════ */
// 7-day average daily usage per ingredient (for forecast)
$fc_map = [];
$fc_res = $conn->query("SELECT ingredient_id, SUM(amount)/7 AS daily_avg FROM ingredient_history WHERE change_type='order_deduct' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY ingredient_id");
if ($fc_res) while ($fc = $fc_res->fetch_assoc()) $fc_map[(int)$fc['ingredient_id']] = (float)$fc['daily_avg'];

$rows = [];
$res  = mysqli_query($conn, "SELECT i.*, s.name AS supplier_name FROM ingredients i LEFT JOIN suppliers s ON s.supplier_id = i.supplier_id ORDER BY i.ingredient_name ASC");
while ($r = mysqli_fetch_assoc($res)) {
    $stock = (float)$r['stock_quantity'];
    $min   = (float)$r['minimum_stock'];
    $cpu   = (float)$r['cost_per_unit'];
    if ($cpu <= 0 && (float)($r['purchase_qty'] ?? 0) > 0)
        $cpu = (float)$r['cost_price'] / (float)$r['purchase_qty'];
    $value = $stock * $cpu;
    $pct   = $min > 0 ? min(100, round($stock / $min * 100)) : 100;
    if ($stock <= 0)       $status = 'out';
    elseif ($stock < $min) $status = 'low';
    else                   $status = 'ok';
    $unitLc   = strtolower(trim($r['unit'] ?? ''));
    $dispCpu  = $cpu;
    $dispSfx  = $unitLc ? ('/'.$unitLc) : '';
    if ($unitLc === 'g')  { $dispCpu = $cpu * 1000; $dispSfx = '/kg'; }
    if ($unitLc === 'ml') { $dispCpu = $cpu * 1000; $dispSfx = '/L';  }
    $da = $fc_map[(int)$r['ingredient_id']] ?? 0;
    $rows[] = array_merge($r, ['stock'=>$stock,'min'=>$min,'cpu'=>$cpu,'value'=>$value,'pct'=>$pct,'status'=>$status,'disp_cpu'=>$dispCpu,'disp_sfx'=>$dispSfx,'daily_avg'=>$da]);
}

$total    = count($rows);
$cnt_ok   = count(array_filter($rows, fn($r) => $r['status']==='ok'));
$cnt_low  = count(array_filter($rows, fn($r) => $r['status']==='low'));
$cnt_out  = count(array_filter($rows, fn($r) => $r['status']==='out'));
$stk_val  = array_sum(array_column($rows, 'value'));
$critical = $cnt_low + $cnt_out;


function fmt($n)   { return rtrim(rtrim(number_format((float)$n,2,'.','' ),'0'),'.'); }
function money($n) { return number_format((float)$n,2); }
function h($s)     { return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingredients Stock | Bird's Nest Coffee</title>
<script>(function(){if(localStorage.getItem('theme')==='light')document.documentElement.setAttribute('data-theme','light');}());</script>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
/* ── VARS ── */
:root {
    --bg:#0b0b0b; --bg-card:#131313; --bg-card-hover:#1a1a1a; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a; --accent-dark:#a0702a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --ok:#55e087; --low:#f1c40f; --danger:#ff5f5f; --blue:#3498db; --purple:#9b59b6;
    --shadow-sm:0 2px 8px rgba(0,0,0,.35); --shadow-md:0 4px 20px rgba(0,0,0,.45);
    --shadow-accent:0 0 0 3px rgba(209,144,75,.12);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
}
[data-theme="light"] {
    --bg:#F0F2F5; --bg-card:#FFFFFF; --bg-card-hover:#F5F7FA; --bg-input:#F9FAFB;
    --border:#E5E7EB; --border-hover:#D1D5DB;
    --text:#111827; --text-muted:#6B7280; --text-light:#111827;
    --shadow-sm:0 2px 8px rgba(0,0,0,.06); --shadow-md:0 4px 20px rgba(0,0,0,.08);
}
[data-theme="light"] .topbar            { background:rgba(255,255,255,.97); }
[data-theme="light"] thead th           { background:#fff; }
[data-theme="light"] tr:hover td        { background:rgba(0,0,0,.02); }
[data-theme="light"] .row-low td        { background:rgba(241,196,15,.04); }
[data-theme="light"] .row-out td        { background:rgba(255,95,95,.04); }
[data-theme="light"] input:not([type=checkbox]),
[data-theme="light"] select             { background:var(--bg-input)!important; color:var(--text)!important; border-color:var(--border)!important; color-scheme:light; }

/* ── RESET ── */
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text); min-height:100vh; padding-bottom:56px; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-thumb { background:var(--accent); border-radius:10px; }

/* ── TOPBAR ── */
.topbar {
    position:sticky; top:0; z-index:200;
    display:flex; align-items:center; gap:10px; padding:10px 24px;
    background:rgba(11,11,11,.97); backdrop-filter:blur(20px);
    border-bottom:1px solid var(--border); flex-wrap:wrap;
}
.topbar-brand  { display:flex; align-items:center; gap:10px; }
.brand-icon    { width:34px; height:34px; border-radius:9px; background:linear-gradient(135deg,var(--accent-dark),var(--accent)); display:flex; align-items:center; justify-content:center; font-size:15px; color:#fff; flex-shrink:0; }
.brand-text    { display:flex; flex-direction:column; line-height:1.2; }
.brand-title   { font-size:15px; font-weight:700; color:var(--text-light); }
.brand-sub     { font-size:10px; color:var(--text-muted); }
.topbar-sep    { width:1px; height:22px; background:var(--border); flex-shrink:0; }
.topbar-center { flex:1; display:flex; justify-content:center; min-width:180px; }
.topbar-right  { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }

/* ── SEARCH ── */
.search-wrap {
    display:flex; align-items:center; gap:8px; padding:8px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    transition:var(--transition); max-width:300px; width:100%;
}
.search-wrap:focus-within { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.search-wrap i.icon { color:var(--text-muted); font-size:12px; flex-shrink:0; }
.search-wrap input  { border:none; background:transparent; outline:none; color:var(--text); font-size:13px; font-family:'Poppins',sans-serif; flex:1; min-width:0; }
.search-wrap input::placeholder { color:var(--text-muted); }
#clearSearch { background:none; border:none; color:var(--text-muted); cursor:pointer; padding:0; font-size:12px; transition:var(--transition); display:none; align-items:center; }
#clearSearch.visible { display:flex; }
#clearSearch:hover { color:var(--danger); }
.kbd { font-size:10px; color:var(--text-muted); background:rgba(255,255,255,.06); padding:1px 6px; border-radius:4px; border:1px solid var(--border); font-family:monospace; white-space:nowrap; }

/* ── BUTTONS ── */
.btn-nav {
    display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
    border-radius:50px; border:1px solid var(--border); background:var(--bg-input);
    color:var(--text-muted); text-decoration:none; font-size:12px; font-weight:500;
    transition:var(--transition); cursor:pointer; white-space:nowrap; font-family:'Poppins',sans-serif;
}
.btn-nav:hover { border-color:var(--accent); color:var(--accent); }
.btn-nav.primary { background:var(--accent); color:#000; border-color:var(--accent); font-weight:700; }
.btn-nav.primary:hover { background:var(--accent-light); }
.btn-nav.icon-only { padding:7px 10px; }
.badge-count { background:var(--danger); color:#fff; border-radius:20px; padding:1px 6px; font-size:10px; font-weight:700; }

/* ── CRITICAL BANNER ── */
.critical-banner {
    margin:12px 24px 0; padding:11px 16px;
    background:rgba(255,95,95,.06); border:1px solid rgba(255,95,95,.18);
    border-radius:10px; display:flex; align-items:center; gap:10px;
    font-size:13px; animation:fadeSlide .35s ease;
}
.critical-banner i { color:var(--danger); flex-shrink:0; }
.critical-banner .btn-link { margin-left:auto; color:var(--accent); background:none; border:none; cursor:pointer; font-family:'Poppins',sans-serif; font-size:12px; font-weight:600; white-space:nowrap; }
.critical-banner .btn-link:hover { text-decoration:underline; }
@keyframes fadeSlide { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }

/* ── STATS ── */
.stats-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; padding:14px 24px 0; }
.stat-card {
    background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
    padding:16px 18px; display:flex; align-items:center; gap:12px;
    transition:var(--transition); cursor:pointer; position:relative; overflow:hidden;
}
.stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; border-radius:var(--radius) var(--radius) 0 0; }
.stat-card.s-accent::before { background:linear-gradient(90deg,var(--accent-dark),var(--accent-light)); }
.stat-card.s-ok::before     { background:linear-gradient(90deg,#2ecc71,var(--ok)); }
.stat-card.s-low::before    { background:linear-gradient(90deg,#d4ac0d,var(--low)); }
.stat-card.s-out::before    { background:linear-gradient(90deg,#c0392b,var(--danger)); }
.stat-card.s-val::before    { background:linear-gradient(90deg,#2471a3,var(--blue)); }
.stat-card:hover  { border-color:var(--border-hover); box-shadow:var(--shadow-md); transform:translateY(-2px); }
.stat-card.active { border-color:var(--accent); box-shadow:var(--shadow-accent); }
.stat-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
.stat-icon.accent { background:rgba(209,144,75,.12); color:var(--accent); }
.stat-icon.ok     { background:rgba(85,224,135,.12);  color:var(--ok); }
.stat-icon.low    { background:rgba(241,196,15,.12);  color:var(--low); }
.stat-icon.out    { background:rgba(255,95,95,.12);   color:var(--danger); }
.stat-icon.val    { background:rgba(52,152,219,.12);  color:var(--blue); }
.stat-label { font-size:10px; color:var(--text-muted); font-weight:500; text-transform:uppercase; letter-spacing:.5px; }
.stat-num   { font-size:21px; font-weight:800; line-height:1.15; }
.stat-hint  { font-size:10px; color:var(--text-muted); margin-top:1px; }
.s-accent .stat-num { color:var(--text-light); }
.s-ok  .stat-num { color:var(--ok); }
.s-low .stat-num { color:var(--low); }
.s-out .stat-num { color:var(--danger); }
.s-val .stat-num { color:var(--blue); }

/* ── CONTROLS BAR ── */
.controls-bar { display:flex; align-items:center; gap:8px; padding:12px 24px 0; flex-wrap:wrap; }
.filter-pill { display:inline-flex; align-items:center; gap:6px; padding:6px 13px; border-radius:50px; border:1px solid var(--border); background:var(--bg-card); color:var(--text-muted); font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition); user-select:none; }
.filter-pill:hover { border-color:var(--accent); color:var(--accent); }
.filter-pill.active     { background:var(--accent); color:#000; border-color:var(--accent); }
.filter-pill.active-ok  { background:var(--ok);     color:#000; border-color:var(--ok); }
.filter-pill.active-low { background:var(--low);    color:#000; border-color:var(--low); }
.filter-pill.active-out { background:var(--danger); color:#fff; border-color:var(--danger); }
.pill-count { font-size:10px; font-weight:700; padding:1px 5px; border-radius:20px; background:rgba(255,255,255,.2); }
.controls-right { display:flex; align-items:center; gap:8px; margin-left:auto; }
.row-count { font-size:12px; color:var(--text-muted); white-space:nowrap; }
.compact-btn { display:flex; align-items:center; gap:5px; padding:5px 10px; border-radius:7px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:11px; cursor:pointer; font-family:'Poppins',sans-serif; transition:var(--transition); }
.compact-btn:hover { border-color:var(--border-hover); color:var(--text); }
.compact-btn.on { border-color:var(--accent); color:var(--accent); }

/* ── TABLE ── */
.table-card { margin:12px 24px 0; background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
.table-wrap { overflow:auto; max-height:calc(100vh - 310px); }
table { width:100%; border-collapse:collapse; font-size:13px; }
thead { position:sticky; top:0; z-index:10; }
th { padding:10px 14px; text-align:left; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.6px; color:var(--text-muted); background:var(--bg-card); border-bottom:1px solid var(--border); white-space:nowrap; cursor:pointer; user-select:none; transition:color .18s; }
th:hover { color:var(--accent); }
th.sorted { color:var(--accent); }
th .si { margin-left:4px; opacity:.3; font-size:9px; }
th.sorted .si { opacity:1; }
th:last-child { cursor:default; }
th:last-child:hover { color:var(--text-muted); }
td { padding:11px 14px; border-bottom:1px solid var(--border); color:var(--text); white-space:nowrap; transition:background .12s; vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:rgba(255,255,255,.025); }
tr.row-low  td { background:rgba(241,196,15,.03); }
tr.row-out  td { background:rgba(255,95,95,.04); }
tr.row-low:hover td { background:rgba(241,196,15,.07); }
tr.row-out:hover td  { background:rgba(255,95,95,.08); }
tr.hidden { display:none !important; }
tr.compact td { padding:6px 14px; }
tfoot td { padding:10px 14px; font-size:11px; font-weight:700; border-top:2px solid var(--border); color:var(--text-muted); background:rgba(255,255,255,.015); white-space:nowrap; }
.foot-value { color:var(--blue); font-size:13px; }
.foot-label { color:var(--text-muted); }

/* ── STOCK BAR ── */
.stock-cell { display:flex; flex-direction:column; gap:3px; min-width:130px; }
.stock-val  { font-size:13px; font-weight:600; }
.stock-bar  { height:4px; background:rgba(255,255,255,.07); border-radius:4px; overflow:hidden; width:100%; }
.stock-fill { height:100%; border-radius:4px; transition:width .5s ease; }
.fill-ok    { background:linear-gradient(90deg,#2ecc71,var(--ok)); }
.fill-low   { background:linear-gradient(90deg,#d4ac0d,var(--low)); }
.fill-out   { background:var(--danger); }
.stock-pct      { font-size:10px; color:var(--text-muted); }
.stock-forecast { font-size:10px; display:flex; align-items:center; gap:3px; margin-top:2px; font-weight:600; }
.fc-ok          { color:var(--ok); }
.fc-warn        { color:var(--low); }
.fc-critical    { color:var(--danger); }

/* ── BADGES ── */
.badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:50px; font-size:11px; font-weight:700; }
.badge.ok  { background:rgba(85,224,135,.1);  color:var(--ok);     border:1px solid rgba(85,224,135,.2); }
.badge.low { background:rgba(241,196,15,.1);  color:var(--low);    border:1px solid rgba(241,196,15,.2); animation:plow 2.5s infinite; }
.badge.out { background:rgba(255,95,95,.1);   color:var(--danger); border:1px solid rgba(255,95,95,.2);  animation:pout 1.8s infinite; }
@keyframes plow { 0%,100%{opacity:1} 50%{opacity:.55} }
@keyframes pout { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.06)} }

/* ── INLINE EDIT ── */
.editable { cursor:pointer; border-bottom:1px dotted var(--text-muted); transition:color .15s; }
.editable:hover { color:var(--accent); border-color:var(--accent); }
.inline-input { width:80px; padding:3px 8px; border-radius:6px; border:1px solid var(--accent); background:var(--bg-input); color:var(--text); font-size:12px; font-family:'Poppins',sans-serif; outline:none; box-shadow:0 0 0 3px rgba(209,144,75,.12); }

/* ── ING NAME ── */
.ing-name { display:flex; align-items:center; gap:9px; font-weight:500; }
.ing-icon { width:28px; height:28px; border-radius:7px; background:rgba(209,144,75,.1); display:flex; align-items:center; justify-content:center; font-size:12px; color:var(--accent); flex-shrink:0; }
.cost-hl  { color:var(--accent); font-weight:600; }

/* ── ROW ACTIONS ── */
.row-actions { display:flex; gap:5px; }
.btn-row { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:7px; font-size:11px; font-weight:600; cursor:pointer; text-decoration:none; transition:var(--transition); border:1px solid transparent; font-family:'Poppins',sans-serif; }
.btn-row.edit    { background:rgba(209,144,75,.08); color:var(--accent); border-color:rgba(209,144,75,.2); }
.btn-row.restock { background:rgba(85,224,135,.08); color:var(--ok);    border-color:rgba(85,224,135,.2); }
.btn-row.del     { background:rgba(255,95,95,.06);  color:var(--danger); border-color:rgba(255,95,95,.15); padding:4px 8px; }
.btn-row.edit:hover    { background:var(--accent); color:#000; }
.btn-row.restock:hover { background:var(--ok); color:#000; }
.btn-row.del:hover     { background:var(--danger); color:#fff; }
.btn-row.adjust { background:rgba(241,196,15,.08); color:var(--low); border-color:rgba(241,196,15,.3); }
.btn-row.adjust:hover { background:var(--low); color:#000; }

/* ── EMPTY STATE ── */
.empty-state { text-align:center; padding:60px 20px; }
.empty-state .ei { font-size:42px; color:var(--border-hover); margin-bottom:14px; }
.empty-state h3  { font-size:16px; font-weight:600; color:var(--text); margin-bottom:6px; }
.empty-state p   { font-size:13px; color:var(--text-muted); margin-bottom:18px; }

/* ── MODAL ── */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.72); backdrop-filter:blur(10px); z-index:9999; display:none; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--bg-card); border:1px solid var(--border); border-radius:20px; padding:28px; max-width:420px; width:100%; box-shadow:0 24px 64px rgba(0,0,0,.65); animation:popIn .24s ease both; position:relative; }
.modal-box.sm { max-width:360px; }
@keyframes popIn { from{opacity:0;transform:scale(.92) translateY(14px)} to{opacity:1;transform:scale(1) translateY(0)} }
.modal-close { position:absolute; top:14px; right:14px; width:30px; height:30px; border-radius:50%; border:none; background:rgba(255,255,255,.06); color:var(--text-muted); font-size:14px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:var(--transition); }
.modal-close:hover { color:var(--text); background:rgba(255,255,255,.12); }
.modal-title { font-size:17px; font-weight:700; margin-bottom:4px; color:var(--text-light); display:flex; align-items:center; gap:8px; }
.modal-sub   { font-size:12px; color:var(--text-muted); margin-bottom:20px; font-weight:500; }
.modal-label { font-size:10px; font-weight:700; color:var(--text-muted); margin-bottom:6px; display:block; text-transform:uppercase; letter-spacing:.5px; }
.modal-input { width:100%; padding:11px 14px; border-radius:10px; border:1px solid var(--border); background:var(--bg-input); color:var(--text); font-size:14px; font-weight:600; font-family:'Poppins',sans-serif; outline:none; transition:var(--transition); margin-bottom:14px; }
.modal-input:focus { border-color:var(--ok); box-shadow:0 0 0 3px rgba(85,224,135,.1); }
.modal-input.note { font-size:13px; font-weight:400; }
.modal-row { display:flex; justify-content:space-between; align-items:center; padding:10px 14px; border-radius:10px; background:var(--bg-input); margin-bottom:14px; }
.modal-row .ml { font-size:11px; color:var(--text-muted); font-weight:600; text-transform:uppercase; letter-spacing:.4px; }
.modal-row .mv { font-size:15px; font-weight:700; color:var(--text-light); }
.quick-amounts { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
.qa { padding:5px 12px; border-radius:20px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:12px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; }
.qa:hover { border-color:var(--ok); color:var(--ok); background:rgba(85,224,135,.06); }
.qa.smart { border-color:rgba(241,196,15,.4); color:var(--low); }
.qa.smart:hover { background:rgba(241,196,15,.08); border-color:var(--low); }
.btn-confirm { width:100%; padding:12px; border-radius:10px; border:none; background:var(--ok); color:#000; font-size:14px; font-weight:700; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; display:flex; align-items:center; justify-content:center; gap:8px; }
.btn-confirm:hover { filter:brightness(1.08); transform:translateY(-1px); }
.btn-confirm:disabled { opacity:.6; transform:none; cursor:not-allowed; }
.btn-confirm.danger  { background:var(--danger); color:#fff; }
.btn-cancel { width:100%; margin-top:8px; padding:10px; border-radius:10px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif; }
.btn-cancel:hover { border-color:var(--border-hover); color:var(--text); }
.direction-btn {
    display:none; flex:1; padding:9px 14px; border-radius:9px; border:1px solid var(--border);
    background:transparent; color:var(--text-muted); font-size:12px; font-weight:600;
    cursor:pointer; transition:var(--transition); font-family:'Poppins',sans-serif;
    display:inline-flex; align-items:center; justify-content:center; gap:6px;
}
.divider { height:1px; background:var(--border); margin:14px 0; }

/* ── TOAST ── */
#toast-cnt { position:fixed; bottom:24px; right:20px; z-index:99999; display:flex; flex-direction:column-reverse; gap:8px; pointer-events:none; }
.toast { background:var(--bg-card); border:1px solid var(--border); border-radius:12px; padding:11px 16px; font-size:13px; font-weight:500; color:var(--text); box-shadow:var(--shadow-md); display:flex; align-items:center; gap:10px; min-width:220px; max-width:320px; transform:translateX(120%); transition:transform .3s cubic-bezier(.34,1.56,.64,1); pointer-events:auto; }
.toast.show { transform:translateX(0); }
.toast.success { border-left:3px solid var(--ok); }
.toast.error   { border-left:3px solid var(--danger); }

/* ── SHORTCUTS BAR ── */
.sc-bar { position:fixed; bottom:16px; left:20px; display:flex; gap:14px; font-size:11px; color:var(--text-muted); pointer-events:none; }
.sc-bar span { display:flex; align-items:center; gap:4px; }
.sc-key { background:rgba(255,255,255,.06); border:1px solid var(--border); border-radius:4px; padding:1px 5px; font-family:monospace; font-size:10px; }

/* ── RESPONSIVE ── */
@media (max-width:1100px) { .stats-row { grid-template-columns:repeat(3,1fr); } }
@media (max-width:900px)  { .topbar-center { display:none; } .stats-row { grid-template-columns:repeat(2,1fr); } }
@media (max-width:768px)  {
    .stats-row,.controls-bar,.table-card,.critical-banner { margin-left:14px; margin-right:14px; }
    .topbar { padding:10px 14px; }
    td,th { padding:8px 10px; font-size:12px; }
    .stats-row { padding:12px 14px 0; }
    .table-wrap { max-height:calc(100vh - 270px); }
    .sc-bar { display:none; }
}
@media (max-width:480px) { .stats-row { grid-template-columns:1fr 1fr; } }
@media print {
    .topbar,.controls-bar,.row-actions,#toast-cnt,.modal-overlay,.sc-bar,.critical-banner { display:none!important; }
    body { padding:0; background:#fff; color:#000; }
    .table-card { box-shadow:none; border:1px solid #ccc; margin:0; }
    .table-wrap { max-height:none; overflow:visible; }
    td,th { font-size:11px; border-color:#ddd!important; }
    tfoot td { border-top:2px solid #ccc!important; }
}
@media (prefers-reduced-motion:reduce) { *,*::before,*::after { transition:none!important; animation:none!important; } }

/* ── HISTORY DRAWER ── */
.history-overlay { position:fixed; inset:0; background:rgba(0,0,0,.55); backdrop-filter:blur(6px); z-index:9998; display:none; }
.history-overlay.open { display:block; }
.history-drawer { position:fixed; top:0; right:0; height:100%; width:360px; max-width:95vw; background:var(--bg-card); border-left:1px solid var(--border); box-shadow:-10px 0 50px rgba(0,0,0,.55); display:flex; flex-direction:column; transform:translateX(100%); transition:transform .28s cubic-bezier(.4,0,.2,1); z-index:9999; }
.history-overlay.open .history-drawer { transform:translateX(0); }
.history-hdr { display:flex; align-items:flex-start; justify-content:space-between; padding:20px 20px 16px; border-bottom:1px solid var(--border); flex-shrink:0; }
.history-title { font-size:15px; font-weight:700; color:var(--text-light); }
.history-sub { font-size:11px; color:var(--text-muted); margin-top:3px; font-weight:500; }
.history-body { flex:1; overflow-y:auto; padding:16px 20px; }
.h-loading, .h-empty { text-align:center; padding:50px 20px; color:var(--text-muted); font-size:13px; }

.h-entry { display:flex; gap:12px; padding-bottom:14px; margin-bottom:2px; position:relative; }
.h-entry:not(:last-child)::after { content:''; position:absolute; left:11px; top:26px; width:1px; bottom:0; background:var(--border); }
.h-icon { width:24px; height:24px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; flex-shrink:0; margin-top:1px; }
.h-icon.add     { background:rgba(85,224,135,.15); color:var(--ok); }
.h-icon.remove  { background:rgba(255,95,95,.12);  color:var(--danger); }
.h-icon.neutral { background:rgba(241,196,15,.12); color:var(--low); }
.h-content { flex:1; min-width:0; }
.h-type  { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); margin-bottom:2px; }
.h-amt   { font-size:14px; font-weight:700; line-height:1.2; }
.h-amt.pos { color:var(--ok); }
.h-amt.neg { color:var(--danger); }
.h-amt.neu { color:var(--low); }
.h-ref  { font-size:11px; color:var(--text); margin-top:3px; word-break:break-word; }
.h-meta { font-size:10px; color:var(--text-muted); margin-top:4px; }

/* Hide per-row Stock Value column — footer total still shows */
#ingredientTable th:nth-child(6),
#ingredientTable td:nth-child(6) { display:none; }

/* Pagination */
.pg-wrap { padding:14px 18px; border-top:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.pg-nav { display:flex; gap:4px; flex-wrap:wrap; }
.pg-btn { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:13px; font-weight:600; text-decoration:none; transition:var(--transition); }
.pg-btn:hover { border-color:var(--accent); color:var(--accent); }
.pg-active { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; background:var(--accent); border:1px solid var(--accent); color:#000; font-size:13px; font-weight:700; }
.pg-disabled { display:inline-flex; align-items:center; justify-content:center; min-width:32px; height:32px; padding:0 6px; border-radius:8px; border:1px solid var(--border); color:var(--text-muted); font-size:13px; opacity:.35; cursor:default; }
.pg-ellipsis { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; color:var(--text-muted); font-size:13px; }
.pg-info { font-size:12px; color:var(--text-muted); }
</style>
</head>
<body>

<!-- ── TOPBAR ── -->
<div class="topbar">
    <div class="topbar-brand">
        <a href="dashboard.php" class="btn-nav icon-only" title="Back to dashboard"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="brand-icon"><i class="fa-solid fa-flask"></i></div>
        <div class="brand-text">
            <span class="brand-title">Ingredients Stock</span>
            <span class="brand-sub">Bird's Nest Coffee &rsaquo; Admin</span>
        </div>
    </div>
    <div class="topbar-sep"></div>
    <div class="topbar-center">
        <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass icon"></i>
            <input id="searchInput" placeholder="Search ingredients…" autocomplete="off" aria-label="Search ingredients">
            <button id="clearSearch" onclick="clearSearch()" title="Clear"><i class="fa-solid fa-xmark"></i></button>
            <span class="kbd" title="Press / to focus">/</span>
        </div>
    </div>
    <div class="topbar-right">
        <?php if ($critical > 0): ?>
        <button class="btn-nav" onclick="setFilter(null,'critical')" title="<?= $critical ?> items need restocking" style="border-color:rgba(255,95,95,.35);color:var(--danger)">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span class="badge-count"><?= $critical ?></span>
        </button>
        <?php endif; ?>
        <a href="ingredient_history.php" class="btn-nav" title="View stock movement history"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
        <a href="ingredient_report.php" class="btn-nav" title="Consumption summary report"><i class="fa-solid fa-chart-bar"></i> Report</a>
        <button class="btn-nav" onclick="window.open('ingredients_pdf.php','_blank')" title="Export stock report as PDF"><i class="fa-solid fa-file-pdf"></i> Export PDF</button>
        <?php /* "Alert" removed: it called send_report.php?type=stock, which only wrote a
                 PDF into stock_alerts/ — no mail, no notification, despite the label. Real
                 low-stock alerting is cron_stock_alert.php, which actually sends mail(). */ ?>
        <a href="add_ingredient.php" class="btn-nav primary" title="Add new ingredient (N)"><i class="fa-solid fa-plus"></i> Add</a>
        <button class="btn-nav icon-only" onclick="toggleTheme()" id="themeBtn" title="Toggle theme"><i class="fa-solid fa-moon" id="themeIcon"></i></button>
    </div>
</div>

<?php if ($critical > 0): ?>
<!-- ── CRITICAL BANNER ── -->
<div class="critical-banner">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <span>
        <strong><?= $critical ?> ingredient<?= $critical > 1 ? 's' : '' ?></strong> need restocking —
        <?= $cnt_out ?> out of stock, <?= $cnt_low ?> below minimum.
    </span>
    <button class="btn-link" onclick="setFilter(null,'critical')">Show critical only &rarr;</button>
</div>
<?php endif; ?>

<!-- ── UNREVIEWED AUDIT BANNER (Manager / Admin Only) ── -->
<div id="unreviewedBanner" class="critical-banner" style="display:none;background:rgba(52,152,219,.1);border-color:rgba(52,152,219,.3);color:#3498db;margin:12px 24px 0;">
    <i class="fa-solid fa-user-shield"></i>
    <span>
        <strong id="unreviewedCount">0</strong> unreviewed stock adjustment(s) / losses logged by staff.
    </span>
    <button class="btn-link" onclick="openReviewModal()" style="color:#3498db;font-weight:700;">Review Reasons & Details &rarr;</button>
</div>

<!-- ── STATS ── -->
<div class="stats-row">
    <div class="stat-card s-accent" onclick="setFilter(null,'all')" title="Show all">
        <div class="stat-icon accent"><i class="fa-solid fa-flask"></i></div>
        <div>
            <div class="stat-label">Total</div>
            <div class="stat-num" data-target="<?= $total ?>"><?= $total ?></div>
            <div class="stat-hint">Ingredients</div>
        </div>
    </div>
    <div class="stat-card s-ok" onclick="setFilter(null,'ok')" title="Filter: In Stock">
        <div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div>
        <div>
            <div class="stat-label">In Stock</div>
            <div class="stat-num" data-target="<?= $cnt_ok ?>"><?= $cnt_ok ?></div>
            <div class="stat-hint">Available</div>
        </div>
    </div>
    <div class="stat-card s-low" onclick="setFilter(null,'low')" title="Filter: Low Stock">
        <div class="stat-icon low"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <div>
            <div class="stat-label">Low Stock</div>
            <div class="stat-num" data-target="<?= $cnt_low ?>"><?= $cnt_low ?></div>
            <div class="stat-hint">Below minimum</div>
        </div>
    </div>
    <div class="stat-card s-out" onclick="setFilter(null,'out')" title="Filter: Out of Stock">
        <div class="stat-icon out"><i class="fa-solid fa-circle-exclamation"></i></div>
        <div>
            <div class="stat-label">Out of Stock</div>
            <div class="stat-num" data-target="<?= $cnt_out ?>"><?= $cnt_out ?></div>
            <div class="stat-hint">Needs restock</div>
        </div>
    </div>
    <div class="stat-card s-val" style="cursor:default" title="Total inventory value">
        <div class="stat-icon val"><i class="fa-solid fa-dollar-sign"></i></div>
        <div>
            <div class="stat-label">Stock Value</div>
            <div class="stat-num" data-target="<?= $stk_val ?>" data-prefix="$" data-dec="2">$<?= money($stk_val) ?></div>
            <div class="stat-hint">Total investment</div>
        </div>
    </div>
</div>

<!-- ── CONTROLS BAR ── -->
<div class="controls-bar">
    <button class="filter-pill active" data-filter="all" onclick="setFilter(this,'all')">
        <i class="fa-solid fa-layer-group"></i> All <span class="pill-count"><?= $total ?></span>
    </button>
    <button class="filter-pill" data-filter="ok" onclick="setFilter(this,'ok')">
        <i class="fa-solid fa-circle-check"></i> In Stock <span class="pill-count"><?= $cnt_ok ?></span>
    </button>
    <button class="filter-pill" data-filter="low" onclick="setFilter(this,'low')">
        <i class="fa-solid fa-triangle-exclamation"></i> Low <span class="pill-count"><?= $cnt_low ?></span>
    </button>
    <button class="filter-pill" data-filter="out" onclick="setFilter(this,'out')">
        <i class="fa-solid fa-circle-exclamation"></i> Out <span class="pill-count"><?= $cnt_out ?></span>
    </button>
    <div class="controls-right">
        <span class="row-count" id="rowCount">Showing <?= $total ?> of <?= $total ?></span>
        <button class="compact-btn" id="compactBtn" onclick="toggleCompact()" title="Toggle compact rows">
            <i class="fa-solid fa-bars"></i> Compact
        </button>
    </div>
</div>

<!-- ── TABLE ── -->
<div class="table-card">
    <div class="table-wrap">
        <table id="ingredientTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0)" data-col="0">Ingredient <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(1)" data-col="1">Stock Level <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(2)" data-col="2" title="Double-click a value to edit">Minimum <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(3)" data-col="3">Unit <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(4)" data-col="4">Unit Cost <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(5)" data-col="5">Stock Value <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(6)" data-col="6">Status <i class="fa-solid fa-sort si"></i></th>
                    <th onclick="sortTable(7)" data-col="7">Supplier <i class="fa-solid fa-sort si"></i></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="tableBody">
<?php foreach ($rows as $row):
    $fillCls   = match($row['status']) { 'ok'=>'fill-ok','low'=>'fill-low',default=>'fill-out' };
    $rowCls    = match($row['status']) { 'low'=>'row-low','out'=>'row-out',default=>'' };
    $bIcon     = match($row['status']) { 'ok'=>'fa-circle-check','low'=>'fa-triangle-exclamation',default=>'fa-circle-exclamation' };
    $bLabel    = match($row['status']) { 'ok'=>'OK','low'=>'LOW',default=>'OUT' };
    $iid       = (int)$row['ingredient_id'];
    $unit      = h($row['unit'] ?? '');
    $ingName   = h($row['ingredient_name']);
    $da        = (float)$row['daily_avg'];
    $days_rem  = ($da > 0 && $row['stock'] > 0) ? (int)round($row['stock'] / $da) : null;
    $fc_cls    = $days_rem === null ? '' : ($days_rem <= 2 ? 'fc-critical' : ($days_rem <= 7 ? 'fc-warn' : 'fc-ok'));
?>
                <tr class="<?= $rowCls ?>"
                    data-status="<?= $row['status'] ?>"
                    data-name="<?= h(strtolower($row['ingredient_name'])) ?>"
                    data-id="<?= $iid ?>">
                    <td>
                        <div class="ing-name">
                            <div class="ing-icon"><i class="fa-solid fa-leaf"></i></div>
                            <span><?= $ingName ?></span>
                        </div>
                    </td>
                    <td data-val="<?= $row['stock'] ?>">
                        <div class="stock-cell">
                            <div class="stock-val"><?= fmt($row['stock']) ?> <?= $unit ?></div>
                            <div class="stock-bar"><div class="stock-fill <?= $fillCls ?>" style="width:<?= $row['pct'] ?>%"></div></div>
                            <div class="stock-pct"><?= $row['pct'] ?>% of minimum</div>
                            <?php if ($days_rem !== null): ?>
                            <div class="stock-forecast <?= $fc_cls ?>">
                                <i class="fa-solid fa-clock" style="font-size:9px"></i>
                                ~<?= $days_rem ?> day<?= $days_rem !== 1 ? 's' : '' ?> remaining
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-val="<?= $row['min'] ?>">
                        <span class="editable"
                              ondblclick="startInlineEdit(this,<?= $iid ?>,<?= $row['min'] ?>,'<?= $unit ?>')"
                              title="Double-click to edit"><?= fmt($row['min']) ?></span> <?= $unit ?>
                    </td>
                    <td><?= $unit ?: '—' ?></td>
                    <td data-val="<?= $row['cpu'] ?>">
                        <span class="cost-hl">$<?= money($row['disp_cpu']) ?></span><small style="font-size:10px;color:var(--text-muted);margin-left:1px"><?= h($row['disp_sfx']) ?></small>
                    </td>
                    <td data-val="<?= $row['value'] ?>">$<?= money($row['value']) ?></td>
                    <td data-val="<?= $row['status'] ?>">
                        <span class="badge <?= $row['status'] ?>">
                            <i class="fa-solid <?= $bIcon ?>"></i> <?= $bLabel ?>
                        </span>
                    </td>
                    <td data-val="<?= h($row['supplier_name'] ?? '') ?>">
                        <?php if (!empty($row['supplier_name'])): ?>
                        <span style="font-size:12px;color:var(--accent)"><?= h($row['supplier_name']) ?></span>
                        <?php else: ?>
                        <span style="color:var(--border-hover)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn-row edit" href="edit_ingredient.php?id=<?= $iid ?>">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </a>
                            <button class="btn-row"
                                style="background:rgba(52,152,219,.08);color:#3498db;border-color:rgba(52,152,219,.2)"
                                onclick="openHistory(<?= $iid ?>,'<?= $ingName ?>')"
                                title="View stock history">
                                <i class="fa-solid fa-clock-rotate-left"></i>
                            </button>
                            <button class="btn-row adjust"
                                onclick="openAdjust(<?= $iid ?>,'<?= $ingName ?>',<?= $row['stock'] ?>,'<?= $unit ?>')"
                                title="Manual stock adjustment (waste/damage/correction)">
                                <i class="fa-solid fa-sliders"></i> Adjust
                            </button>
                            <button class="btn-row restock"
                                onclick="openRestock(<?= $iid ?>,'<?= $ingName ?>',<?= $row['stock'] ?>,'<?= $unit ?>',<?= $row['min'] ?>)">
                                <i class="fa-solid fa-plus"></i> Add Stock
                            </button>
                            <?php if (in_array($row['status'], ['low','out']) && !empty($row['supplier_id'])): ?>
                            <a class="btn-row" href="purchase_order_create.php?supplier_id=<?= (int)$row['supplier_id'] ?>"
                               style="background:rgba(52,152,219,.08);color:#3498db;border-color:rgba(52,152,219,.2)"
                               title="Create Purchase Order from <?= h($row['supplier_name']) ?>">
                                <i class="fa-solid fa-file-invoice"></i> Order
                            </a>
                            <?php endif; ?>
                            <button class="btn-row del"
                                onclick="confirmDelete(<?= $iid ?>,'<?= $ingName ?>')"
                                title="Delete ingredient">
                                <i class="fa-solid fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
            <tfoot id="tableFoot">
                <tr>
                    <td colspan="9" class="foot-label" id="footLabel">
                        <?= $total ?> ingredients total
                    </td>
                </tr>
            </tfoot>
        </table>

        <!-- EMPTY STATE -->
        <div class="empty-state" id="emptyState" style="display:none">
            <div class="ei"><i class="fa-solid fa-flask"></i></div>
            <h3>No ingredients found</h3>
            <p>Try adjusting your search or filter.</p>
            <button class="btn-nav" onclick="resetFilters()"><i class="fa-solid fa-rotate-left"></i> Reset filters</button>
        </div>
    </div>
    <div id="pgWrap" class="pg-wrap" style="display:none">
        <span id="pgInfo" class="pg-info"></span>
        <nav id="pgNav" class="pg-nav"></nav>
    </div>
</div>

<!-- ── QUICK RESTOCK MODAL ── -->
<div class="modal-overlay" id="restockModal" onclick="if(event.target===this)closeRestock()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeRestock()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-arrow-trend-up" style="color:var(--ok)"></i> Add Stock</div>
        <div class="modal-sub" id="restockSub">Loading…</div>

        <div style="display:flex;gap:10px;margin-bottom:14px">
            <div class="modal-row" style="flex:1;margin:0">
                <div><div class="ml">Current</div><div class="mv" id="restockCurrent">—</div></div>
            </div>
            <div class="modal-row" style="flex:1;margin:0">
                <div><div class="ml">Minimum</div><div class="mv" id="restockMin" style="color:var(--low)">—</div></div>
            </div>
        </div>

        <label class="modal-label">Quick amounts</label>
        <div class="quick-amounts" id="qaWrap"></div>

        <label class="modal-label">Custom amount</label>
        <input class="modal-input" type="number" id="restockAmt" placeholder="Amount to add…" min="0.01" step="any">

        <label class="modal-label">Note <span style="font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
        <input class="modal-input note" type="text" id="restockNote" placeholder="e.g. Weekly delivery from supplier" maxlength="120">

        <input type="hidden" id="restockId">
        <input type="hidden" id="restockUnit">

        <button class="btn-confirm" id="restockBtn" onclick="submitRestock()">
            <i class="fa-solid fa-check"></i> Confirm Add
        </button>
    </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="modal-overlay" id="deleteModal" onclick="if(event.target===this)closeDelete()">
    <div class="modal-box sm">
        <button class="modal-close" onclick="closeDelete()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title" style="color:var(--danger)"><i class="fa-solid fa-triangle-exclamation"></i> Delete Ingredient</div>
        <div class="modal-sub">This cannot be undone. Ingredients used in a recipe cannot be deleted.</div>
        <div class="modal-row" style="border:1px solid rgba(255,95,95,.2);background:rgba(255,95,95,.04)">
            <span class="ml">Ingredient</span>
            <span class="mv" id="deleteName">—</span>
        </div>
        <input type="hidden" id="deleteId">
        <button class="btn-confirm danger" id="deleteBtn" onclick="submitDelete()">
            <i class="fa-solid fa-trash-can"></i> Delete Permanently
        </button>
        <button class="btn-cancel" onclick="closeDelete()">Cancel</button>
    </div>
</div>

<!-- ── MANUAL ADJUST MODAL ── -->
<div class="modal-overlay" id="adjustModal" onclick="if(event.target===this)closeAdjust()">
    <div class="modal-box">
        <button class="modal-close" onclick="closeAdjust()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title"><i class="fa-solid fa-triangle-exclamation" style="color:var(--low)"></i> Stock Deduction</div>
        <div class="modal-sub" id="adjustSub">Loading…</div>

        <div class="modal-row" style="margin-bottom:14px">
            <div><div class="ml">Current Stock</div><div class="mv" id="adjustCurrent">—</div></div>
        </div>

        <label class="modal-label">Amount to Remove</label>
        <input class="modal-input" type="number" id="adjustAmt" placeholder="Quantity to deduct…" min="0.01" step="any">

        <label class="modal-label">Reason Category <span style="color:var(--danger);font-weight:400;text-transform:none;letter-spacing:0">* required</span></label>
        <select class="modal-input" id="adjustCategory" style="margin-bottom:10px;cursor:pointer;">
            <option value="spoilage">⚠️ Spoilage / Expired</option>
            <option value="spillage">🥛 Spill / Waste</option>
            <option value="stock_discrepancy" selected>📦 Stock Count Discrepancy</option>
            <option value="supplier_return">🚚 Supplier Return</option>
            <option value="other">📝 Other Adjustment</option>
        </select>

        <label class="modal-label">Detailed Notes <span style="color:var(--danger);font-weight:400;text-transform:none;letter-spacing:0">* required</span></label>
        <input class="modal-input note" type="text" id="adjustReason" placeholder="e.g. Bottle broken, expired, stock count correction…" maxlength="120">

        <input type="hidden" id="adjustId">
        <input type="hidden" id="adjustUnit">

        <button class="btn-confirm" id="adjustBtn" style="background:var(--danger);color:#fff" onclick="submitAdjust()">
            <i class="fa-solid fa-check"></i> Confirm Deduction
        </button>
        <button class="btn-cancel" onclick="closeAdjust()">Cancel</button>
    </div>
</div>

<!-- ── UNREVIEWED ADJUSTMENTS AUDIT MODAL ── -->
<div class="modal-overlay" id="reviewModal" onclick="if(event.target===this)closeReviewModal()">
    <div class="modal-box" style="max-width:620px;">
        <button class="modal-close" onclick="closeReviewModal()"><i class="fa-solid fa-xmark"></i></button>
        <div class="modal-title" style="color:#3498db;"><i class="fa-solid fa-user-shield"></i> Audit Unreviewed Adjustments</div>
        <div class="modal-sub">Review reasons provided by staff for manual stock adjustments and waste.</div>

        <div class="modal-body" id="reviewModalBody" style="max-height:60vh;overflow-y:auto;margin:14px 0;padding-right:4px;">
            <div style="padding:40px;text-align:center;color:var(--text-muted)">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;color:#3498db"></i>
                Loading adjustments…
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:14px;">
            <button class="btn-cancel" style="flex:1;margin-top:0;" onclick="closeReviewModal()">Close</button>
            <button class="btn-confirm" id="btnMarkAllReviewed" style="flex:1.5;background:#3498db;color:#fff;" onclick="markAllReviewed()">
                <i class="fa-solid fa-circle-check"></i> Approve & Mark All Reviewed
            </button>
        </div>
    </div>
</div>

<!-- ── HISTORY DRAWER ── -->
<div class="history-overlay" id="historyOverlay" onclick="if(event.target===this)closeHistory()">
    <div class="history-drawer">
        <div class="history-hdr">
            <div>
                <div class="history-title"><i class="fa-solid fa-clock-rotate-left" style="color:var(--blue);margin-right:6px"></i>Stock History</div>
                <div class="history-sub" id="historySubtitle">—</div>
            </div>
            <button class="modal-close" style="position:static;flex-shrink:0" onclick="closeHistory()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="history-body" id="historyBody">
            <div class="h-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </div>
</div>

<!-- ── TOAST ── -->
<div id="toast-cnt"></div>

<!-- ── KEYBOARD SHORTCUTS ── -->
<div class="sc-bar">
    <span><span class="sc-key">/</span> Search</span>
    <span><span class="sc-key">N</span> New ingredient</span>
    <span><span class="sc-key">Esc</span> Close</span>
</div>

<script>
/* ── GLOBALS ── */
const TOTAL     = <?= $total ?>;
const TOTAL_VAL = <?= round($stk_val, 4) ?>;
let currentFilter = 'all';
let isCompact = false;

/* ── COUNT-UP ANIMATION ── */
function countUp() {
    document.querySelectorAll('.stat-num[data-target]').forEach(el => {
        const target = parseFloat(el.dataset.target);
        const prefix = el.dataset.prefix || '';
        const dec    = parseInt(el.dataset.dec || 0);
        const dur    = 700, start = performance.now();
        function frame(now) {
            const t = Math.min((now - start) / dur, 1);
            const ease = 1 - Math.pow(1 - t, 3);
            const val  = target * ease;
            el.textContent = prefix + (dec
                ? val.toFixed(dec).replace(/\B(?=(\d{3})+(?!\d))/g, ',')
                : Math.round(val));
            if (t < 1) requestAnimationFrame(frame);
        }
        requestAnimationFrame(frame);
    });
}

/* ── FILTER ── */
function setFilter(btn, filter) {
    currentFilter = filter;
    // Pills
    document.querySelectorAll('.filter-pill').forEach(p => {
        p.className = 'filter-pill';
        const f = p.dataset.filter;
        if (!f) return;
        if (f === filter) {
            p.classList.add(filter === 'all' ? 'active' : 'active-' + filter);
        }
    });
    // Stat card highlight
    document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));
    const map = { all:0, ok:1, low:2, out:3 };
    if (map[filter] !== undefined)
        document.querySelectorAll('.stat-card')[map[filter]]?.classList.add('active');
    applyFilters();
}

/* ── SEARCH ── */
let searchTimer;
document.getElementById('searchInput').addEventListener('input', function() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 150);
    document.getElementById('clearSearch').classList.toggle('visible', this.value.length > 0);
});
function clearSearch() {
    document.getElementById('searchInput').value = '';
    document.getElementById('clearSearch').classList.remove('visible');
    applyFilters();
    document.getElementById('searchInput').focus();
}
function resetFilters() {
    clearSearch();
    setFilter(document.querySelector('[data-filter="all"]'), 'all');
}

/* ── APPLY FILTERS + JS PAGINATION ── */
const PER_PAGE_ING = 10;
let currentPageIng  = 1;
let lastFilteredIng = [];

function applyFilters() {
    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    const allRows = [...document.querySelectorAll('#tableBody tr[data-name]')];
    lastFilteredIng = allRows.filter(row => {
        const nameOk = !q || row.dataset.name.includes(q);
        const statOk = currentFilter === 'all' ? true
            : currentFilter === 'critical'
                ? (row.dataset.status === 'low' || row.dataset.status === 'out')
                : row.dataset.status === currentFilter;
        return nameOk && statOk;
    });
    currentPageIng = 1;
    renderPageIng();
}

function renderPageIng() {
    const total      = lastFilteredIng.length;
    const totalPages = Math.max(1, Math.ceil(total / PER_PAGE_ING));
    currentPageIng   = Math.min(currentPageIng, totalPages);
    const start      = (currentPageIng - 1) * PER_PAGE_ING;
    const pageRows   = lastFilteredIng.slice(start, start + PER_PAGE_ING);

    document.querySelectorAll('#tableBody tr[data-name]').forEach(r => r.classList.add('hidden'));
    pageRows.forEach(r => r.classList.remove('hidden'));

    document.getElementById('emptyState').style.display = total === 0 ? 'block' : 'none';
    document.getElementById('rowCount').textContent  = `Showing ${total} of ${TOTAL}`;
    document.getElementById('footLabel').textContent = `Showing ${total} of ${TOTAL} ingredients`;

    renderPaginationIng(total, totalPages);
}

function renderPaginationIng(total, totalPages) {
    const wrap = document.getElementById('pgWrap');
    if (totalPages <= 1) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'flex';
    document.getElementById('pgInfo').textContent =
        `Page ${currentPageIng} of ${totalPages} · ${total} shown`;
    const nav = document.getElementById('pgNav');
    let html = currentPageIng > 1
        ? `<a href="#" class="pg-btn" onclick="goPageIng(1);return false;">«</a><a href="#" class="pg-btn" onclick="goPageIng(${currentPageIng - 1});return false;">‹</a>`
        : `<span class="pg-disabled">«</span><span class="pg-disabled">‹</span>`;
    const ws = Math.max(1, currentPageIng - 2);
    const we = Math.min(totalPages, currentPageIng + 2);
    if (ws > 1) html += `<span class="pg-ellipsis">…</span>`;
    for (let i = ws; i <= we; i++) {
        html += i === currentPageIng
            ? `<span class="pg-active">${i}</span>`
            : `<a href="#" class="pg-btn" onclick="goPageIng(${i});return false;">${i}</a>`;
    }
    if (we < totalPages) html += `<span class="pg-ellipsis">…</span>`;
    html += currentPageIng < totalPages
        ? `<a href="#" class="pg-btn" onclick="goPageIng(${currentPageIng + 1});return false;">›</a><a href="#" class="pg-btn" onclick="goPageIng(${totalPages});return false;">»</a>`
        : `<span class="pg-disabled">›</span><span class="pg-disabled">»</span>`;
    nav.innerHTML = html;
}

function goPageIng(p) {
    currentPageIng = p;
    renderPageIng();
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
    applyFilters();
}

/* ── COMPACT VIEW ── */
function toggleCompact() {
    isCompact = !isCompact;
    document.querySelectorAll('#tableBody tr').forEach(r => r.classList.toggle('compact', isCompact));
    document.getElementById('compactBtn').classList.toggle('on', isCompact);
}

/* ── INLINE MIN EDIT ── */
function startInlineEdit(span, id, curMin, unit) {
    const td = span.parentElement;
    const inp = document.createElement('input');
    inp.type = 'number'; inp.className = 'inline-input';
    inp.value = curMin; inp.min = '0'; inp.step = 'any';
    td.innerHTML = ''; td.appendChild(inp);
    inp.focus(); inp.select();

    const restore = () => {
        td.innerHTML = `<span class="editable" ondblclick="startInlineEdit(this,${id},${curMin},'${unit}')" title="Double-click to edit">${fmt(curMin)}</span> ${unit}`;
    };
    const save = async () => {
        const val = parseFloat(inp.value);
        if (isNaN(val) || val < 0 || val === curMin) { restore(); return; }
        try {
            const res  = await fetch('ingredients.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=update_min&ingredient_id=${id}&minimum_stock=${val}` });
            const data = await res.json();
            if (data.success) {
                td.dataset.val = val;
                td.innerHTML = `<span class="editable" ondblclick="startInlineEdit(this,${id},${val},'${unit}')" title="Double-click to edit">${fmt(val)}</span> ${unit}`;
                showToast(`Minimum updated → ${fmt(val)} ${unit}`, 'success');
            } else restore();
        } catch { restore(); }
    };
    inp.addEventListener('blur', save);
    inp.addEventListener('keydown', e => {
        if (e.key === 'Enter')  { e.preventDefault(); inp.blur(); }
        if (e.key === 'Escape') { e.preventDefault(); inp.removeEventListener('blur', save); restore(); }
    });
}

/* ── RESTOCK MODAL ── */
function openRestock(id, name, stock, unit, minStock) {
    document.getElementById('restockId').value   = id;
    document.getElementById('restockUnit').value  = unit;
    document.getElementById('restockSub').textContent = name;
    document.getElementById('restockCurrent').textContent = fmt(stock) + (unit ? ' ' + unit : '');
    document.getElementById('restockMin').textContent     = fmt(minStock) + (unit ? ' ' + unit : '');
    document.getElementById('restockAmt').value  = '';
    document.getElementById('restockNote').value = '';

    const needed = minStock - stock;
    const qa = document.getElementById('qaWrap');
    let btns = '';
    if (needed > 0) btns += `<button class="qa smart" onclick="setAmt(${+needed.toFixed(2)})" title="Reach minimum">To min (+${Math.ceil(needed)})</button>`;
    [100, 250, 500, 1000].forEach(n => { btns += `<button class="qa" onclick="setAmt(${n})">+${n}</button>`; });
    qa.innerHTML = btns;

    document.getElementById('restockModal').classList.add('open');
    setTimeout(() => document.getElementById('restockAmt').focus(), 100);
}
function closeRestock() { document.getElementById('restockModal').classList.remove('open'); }
function setAmt(n) { document.getElementById('restockAmt').value = n; document.getElementById('restockAmt').focus(); }

async function submitRestock() {
    const id   = document.getElementById('restockId').value;
    const amt  = parseFloat(document.getElementById('restockAmt').value);
    const unit = document.getElementById('restockUnit').value;
    const note = document.getElementById('restockNote').value.trim();
    if (!amt || amt <= 0) { showToast('Enter a valid amount', 'error'); return; }
    const btn = document.getElementById('restockBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
    try {
        const res  = await fetch('ingredients.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=quick_restock&ingredient_id=${id}&amount=${amt}&note=${encodeURIComponent(note)}` });
        const data = await res.json();
        if (data.success) {
            updateRow(parseInt(id), data.new_stock, data.min_stock, unit);
            closeRestock();
            showToast(`+${fmt(amt)}${unit ? ' '+unit : ''} added to stock`, 'success');
        } else {
            showToast(data.message || 'Restock failed', 'error');
        }
    } catch { showToast('Network error', 'error'); }
    finally  { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Add'; }
}

function updateRow(id, newStock, minStock, unit, dailyAvg) {
    const row = document.querySelector(`#tableBody tr[data-id="${id}"]`);
    if (!row) return;
    const pct    = minStock > 0 ? Math.min(100, Math.round(newStock / minStock * 100)) : 100;
    const status = newStock <= 0 ? 'out' : (newStock < minStock ? 'low' : 'ok');
    const fills  = { ok:'fill-ok', low:'fill-low', out:'fill-out' };
    const badges = { ok:['OK','fa-circle-check'], low:['LOW','fa-triangle-exclamation'], out:['OUT','fa-circle-exclamation'] };
    const [lbl, ico] = badges[status];

    // Stock cell
    const sc = row.children[1];
    sc.dataset.val = newStock;
    sc.querySelector('.stock-val').textContent = fmt(newStock) + (unit ? ' ' + unit : '');
    const fill = sc.querySelector('.stock-fill');
    fill.className = 'stock-fill ' + fills[status];
    fill.style.width = pct + '%';
    sc.querySelector('.stock-pct').textContent = pct + '% of minimum';

    // Forecast chip
    if (dailyAvg !== undefined) {
        let fc = sc.querySelector('.stock-forecast');
        const da = parseFloat(dailyAvg) || 0;
        const daysRem = (da > 0 && newStock > 0) ? Math.round(newStock / da) : null;
        if (daysRem !== null) {
            const fcCls = daysRem <= 2 ? 'fc-critical' : (daysRem <= 7 ? 'fc-warn' : 'fc-ok');
            if (!fc) { fc = document.createElement('div'); fc.className = 'stock-forecast'; sc.querySelector('.stock-cell').appendChild(fc); }
            fc.className = 'stock-forecast ' + fcCls;
            fc.innerHTML = `<i class="fa-solid fa-clock" style="font-size:9px"></i> ~${daysRem} day${daysRem !== 1 ? 's' : ''} remaining`;
        } else if (fc) { fc.remove(); }
    }

    // Value cell
    const cpu = parseFloat(row.children[4]?.dataset?.val) || 0;
    const newVal = newStock * cpu;
    row.children[5].dataset.val = newVal;
    row.children[5].textContent = '$' + newVal.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    // Badge
    const badge = row.querySelector('.badge');
    badge.className = 'badge ' + status;
    badge.innerHTML = `<i class="fa-solid ${ico}"></i> ${lbl}`;

    // Row state
    row.classList.remove('row-low','row-out');
    if (status === 'low') row.classList.add('row-low');
    if (status === 'out') row.classList.add('row-out');
    row.dataset.status = status;

    applyFilters();
}

/* ── MANUAL ADJUST (deduction only) ── */
function openAdjust(id, name, stock, unit) {
    document.getElementById('adjustId').value = id;
    document.getElementById('adjustUnit').value = unit;
    document.getElementById('adjustSub').textContent = name;
    document.getElementById('adjustCurrent').textContent = fmt(stock) + (unit ? ' ' + unit : '');
    document.getElementById('adjustAmt').value = '';
    document.getElementById('adjustReason').value = '';
    document.getElementById('adjustModal').classList.add('open');
    setTimeout(() => document.getElementById('adjustAmt').focus(), 100);
}
function closeAdjust() { document.getElementById('adjustModal').classList.remove('open'); }

async function submitAdjust() {
    const id       = document.getElementById('adjustId').value;
    const amt      = parseFloat(document.getElementById('adjustAmt').value);
    const category = document.getElementById('adjustCategory').value || 'other';
    const reason   = document.getElementById('adjustReason').value.trim();
    const unit     = document.getElementById('adjustUnit').value;

    if (!amt || amt <= 0) { showToast('Enter a valid amount', 'error'); return; }
    if (!reason)           { showToast('Reason notes are required', 'error'); return; }

    const delta  = -amt;
    const btn    = document.getElementById('adjustBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
        const body = `action=manual_adjust&ingredient_id=${id}&delta=${delta}&reason_category=${encodeURIComponent(category)}&reason=${encodeURIComponent(reason)}`;
        const res  = await fetch('ingredients.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body });
        const data = await res.json();
        if (data.success) {
            updateRow(parseInt(id), data.new_stock, data.min_stock, unit);
            closeAdjust();
            showToast(`−${fmt(amt)}${unit ? ' '+unit : ''} deducted — ${reason}`, 'error');
            checkUnreviewedAdjustments();
        } else {
            showToast(data.message || 'Adjustment failed', 'error');
        }
    } catch { showToast('Network error', 'error'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Confirm Deduction'; }
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

const IS_MANAGER_OR_ADMIN = <?= (in_array($_SESSION['role'] ?? '', ['admin', 'manager']) || !empty($_SESSION['is_admin']) || can('manage_roles') || can('report')) ? 'true' : 'false' ?>;

/* ── UNREVIEWED AUDIT FUNCTIONS ── */
async function checkUnreviewedAdjustments() {
    if (!IS_MANAGER_OR_ADMIN) {
        const b = document.getElementById('unreviewedBanner');
        if (b) b.style.display = 'none';
        return;
    }
    try {
        const res = await fetch('ingredients.php?action=get_unreviewed');
        const data = await res.json();
        if (data.ok && data.unreviewed_count > 0) {
            document.getElementById('unreviewedCount').textContent = data.unreviewed_count;
            document.getElementById('unreviewedBanner').style.display = 'flex';
        } else {
            document.getElementById('unreviewedBanner').style.display = 'none';
        }
    } catch (e) {}
}

async function openReviewModal() {
    document.getElementById('reviewModal').classList.add('open');
    const body = document.getElementById('reviewModalBody');
    body.innerHTML = `
        <div style="padding:40px;text-align:center;color:var(--text-muted)">
            <i class="fa-solid fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;color:#3498db"></i>
            Loading adjustments…
        </div>`;

    try {
        const res = await fetch('ingredients.php?action=get_unreviewed');
        const data = await res.json();
        if (!data.ok || data.items.length === 0) {
            body.innerHTML = `
                <div style="padding:40px;text-align:center;color:var(--text-muted)">
                    <i class="fa-solid fa-circle-check" style="font-size:32px;display:block;margin-bottom:10px;color:var(--ok)"></i>
                    All stock adjustments have been reviewed!
                </div>`;
            document.getElementById('unreviewedBanner').style.display = 'none';
            return;
        }

        const catBadge = {
            'spoilage':          '<span style="background:rgba(255,95,95,.15);color:#ff5f5f;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">⚠️ Spoilage / Expired</span>',
            'spillage':          '<span style="background:rgba(241,196,15,.15);color:#f1c40f;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">🥛 Spill / Waste</span>',
            'stock_discrepancy': '<span style="background:rgba(52,152,219,.15);color:#3498db;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">📦 Count Discrepancy</span>',
            'supplier_return':   '<span style="background:rgba(85,224,135,.15);color:#55e087;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">🚚 Supplier Return</span>',
            'restock':           '<span style="background:rgba(85,224,135,.15);color:#55e087;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">➕ Restock</span>',
            'other':             '<span style="background:rgba(209,144,75,.15);color:#d1904b;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;">📝 Manual Adjustment</span>'
        };

        let html = '';
        data.items.forEach(it => {
            const badge = catBadge[it.reason_category] || catBadge['other'];
            const deltaCls = it.amount < 0 ? 'color:var(--danger);font-weight:700;' : 'color:var(--ok);font-weight:700;';
            const deltaStr = it.amount > 0 ? `+${it.amount}` : `${it.amount}`;

            html += `
                <div style="background:var(--bg-input);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <div style="flex:1;">
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                            <span style="font-weight:700;font-size:14px;color:var(--text);">${escHtml(it.ingredient_name)}</span>
                            <span style="${deltaCls}">${deltaStr} ${escHtml(it.unit)}</span>
                            ${badge}
                        </div>
                        <div style="font-size:12px;color:var(--text-muted);margin-bottom:2px;">
                            Reason: <strong style="color:var(--text);">${escHtml(it.reference || 'No reason provided')}</strong>
                        </div>
                        <div style="font-size:10.5px;color:var(--text-muted);">
                            By <strong>${escHtml(it.created_by)}</strong> &bull; ${escHtml(it.created_at)}
                        </div>
                    </div>
                    <button type="button" class="btn-row restock" onclick="markSingleReviewed(${it.history_id}, this)" title="Approve & Mark Reviewed">
                        <i class="fa-solid fa-check"></i> Review
                    </button>
                </div>`;
        });

        body.innerHTML = html;
    } catch (e) {
        body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--danger)">Error loading details (${escHtml(e.message)})</div>`;
    }
}

function closeReviewModal() {
    document.getElementById('reviewModal').classList.remove('open');
}

async function markSingleReviewed(historyId, btn) {
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('ingredients.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_reviewed&history_id=${historyId}`
        });
        const data = await res.json();
        if (data.ok) {
            showToast('Adjustment marked as reviewed', 'success');
            openReviewModal();
            checkUnreviewedAdjustments();
        } else {
            showToast(data.message || 'Failed to update', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    }
}

async function markAllReviewed() {
    const btn = document.getElementById('btnMarkAllReviewed');
    if (btn) btn.disabled = true;
    try {
        const res = await fetch('ingredients.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=mark_reviewed&all=1`
        });
        const data = await res.json();
        if (data.ok) {
            showToast(`Approved ${data.updated_count} adjustments`, 'success');
            closeReviewModal();
            checkUnreviewedAdjustments();
        } else {
            showToast(data.message || 'Failed to update', 'error');
        }
    } catch (e) {
        showToast('Network error', 'error');
    } finally {
        if (btn) btn.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    checkUnreviewedAdjustments();
});

/* ── DELETE ── */
function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteName').textContent = name;
    document.getElementById('deleteModal').classList.add('open');
}
function closeDelete() { document.getElementById('deleteModal').classList.remove('open'); }

async function submitDelete() {
    const id  = document.getElementById('deleteId').value;
    const btn = document.getElementById('deleteBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
    try {
        const res  = await fetch('ingredients.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=delete_ingredient&ingredient_id=${id}` });
        const data = await res.json();
        if (data.success) {
            const row = document.querySelector(`#tableBody tr[data-id="${id}"]`);
            if (row) {
                row.style.transition = 'opacity .25s, transform .25s';
                row.style.opacity = '0'; row.style.transform = 'scale(.97)';
                setTimeout(() => { row.remove(); applyFilters(); }, 260);
            }
            closeDelete();
            showToast('Ingredient deleted', 'success');
        } else {
            showToast(data.message || 'Could not delete', 'error');
        }
    } catch { showToast('Network error', 'error'); }
    finally  { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Delete Permanently'; }
}

/* ── EXPORT CSV (visible rows only, UTF-8 BOM for Excel) ── */
function exportCSV() {
    const headers = ['Ingredient','Stock Qty','Unit','Minimum','Unit Cost','Stock Value','Status','Supplier'];
    const rowData = [];
    document.querySelectorAll('#tableBody tr[data-name]:not(.hidden)').forEach(tr => {
        const c = tr.querySelectorAll('td');
        if (!c.length) return;
        rowData.push([
            c[0]?.querySelector('.ing-name span')?.textContent?.trim() || '',
            c[1]?.dataset?.val || '',
            c[3]?.textContent?.trim() || '',
            c[2]?.dataset?.val || '',
            c[4]?.dataset?.val || '',
            c[5]?.dataset?.val || '',
            (c[6]?.querySelector('.badge')?.textContent?.trim() || '').replace(/\s+/g,' '),
            c[7]?.dataset?.val || '',
        ]);
    });
    const csv  = [headers, ...rowData].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['﻿' + csv], { type:'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `ingredients_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
    showToast(`Exported ${rowData.length} row${rowData.length !== 1 ? 's' : ''}`, 'success');
}

/* ── STOCK ALERT ── */
/* ── TOAST ── */
function showToast(msg, type = 'success') {
    const c = document.getElementById('toast-cnt');
    const t = document.createElement('div');
    t.className = 'toast ' + type;
    const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
    const col  = type === 'success' ? 'var(--ok)' : 'var(--danger)';
    t.innerHTML = `<i class="fa-solid ${icon}" style="color:${col};flex-shrink:0"></i><span>${msg}</span>`;
    c.appendChild(t);
    requestAnimationFrame(() => requestAnimationFrame(() => t.classList.add('show')));
    setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 380); }, 3500);
}

/* ── THEME ── */
function toggleTheme() {
    const html  = document.documentElement;
    const icon  = document.getElementById('themeIcon');
    const light = html.getAttribute('data-theme') === 'light';
    if (light) { html.removeAttribute('data-theme'); icon.className='fa-solid fa-moon'; localStorage.setItem('theme','dark'); }
    else        { html.setAttribute('data-theme','light'); icon.className='fa-solid fa-sun'; localStorage.setItem('theme','light'); }
}

/* ── KEYBOARD SHORTCUTS ── */
document.addEventListener('keydown', e => {
    const tag = document.activeElement?.tagName;
    const inField = tag === 'INPUT' || tag === 'TEXTAREA';
    if (e.key === 'Escape') { closeRestock(); closeDelete(); closeAdjust(); closeHistory(); return; }
    if (inField) return;
    if (e.key === '/' || e.key === 'f') { e.preventDefault(); document.getElementById('searchInput').focus(); }
    if (e.key === 'n' || e.key === 'N') window.location.href = 'add_ingredient.php';
});

/* ── HELPERS ── */
function fmt(n) {
    const s = parseFloat(n).toFixed(4);
    return s.replace(/\.?0+$/, '');
}

/* ── INIT ── */
document.addEventListener('DOMContentLoaded', () => {
    if (localStorage.getItem('theme') === 'light')
        document.getElementById('themeIcon').className = 'fa-solid fa-sun';
    countUp();

    // Auto-activate filter from URL ?filter= param (e.g. from dashboard low-stock banner)
    const urlFilter = new URLSearchParams(location.search).get('filter');
    if (urlFilter && ['ok','low','out','critical'].includes(urlFilter)) {
        setFilter(document.querySelector(`[data-filter="${urlFilter}"]`), urlFilter);
    } else {
        applyFilters();
    }

    // Start real-time polling
    setInterval(pollIngredients, 8000);
});

/* ── REAL-TIME POLLING ── */
function fmtMoney(n) { return '$' + parseFloat(n).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

async function pollIngredients() {
    try {
        const res  = await fetch('ingredients.php?action=poll', { cache:'no-store' });
        const data = await res.json();
        if (!data.ok) return;

        const serverIds = new Set(data.ingredients.map(i => i.id));
        const domRows   = document.querySelectorAll('#tableBody tr[data-id]');

        // Update changed rows
        data.ingredients.forEach(ing => {
            const row = document.querySelector(`#tableBody tr[data-id="${ing.id}"]`);
            if (!row) { location.reload(); return; }
            const curStock = parseFloat(row.children[1].dataset.val);
            const curMin   = parseFloat(row.children[2].dataset.val);
            if (Math.abs(curStock - ing.stock) > 0.0001 || Math.abs(curMin - ing.min) > 0.0001) {
                updateRow(ing.id, ing.stock, ing.min, ing.unit, ing.daily_avg);
                flashRow(row);
            }
        });

        // Fade out deleted rows
        domRows.forEach(row => {
            if (!serverIds.has(parseInt(row.dataset.id))) {
                row.style.transition = 'opacity .4s, transform .4s';
                row.style.opacity = '0'; row.style.transform = 'scale(.97)';
                setTimeout(() => { row.remove(); refreshStatCards(); applyFilters(); }, 420);
            }
        });

        refreshStatCards();
    } catch {}
}

function flashRow(row) {
    row.style.transition = 'background .1s';
    row.style.background = 'rgba(209,144,75,.1)';
    setTimeout(() => { row.style.transition = 'background .7s'; row.style.background = ''; }, 180);
}

/* ── HISTORY DRAWER ── */
const TYPE_CFG = {
    'order_deduct':  { label:'Order Used',      icon:'fa-cart-shopping',  cls:'remove'  },
    'order_restore': { label:'Order Restored',  icon:'fa-rotate-left',    cls:'add'     },
    'quick_restock': { label:'Stock Added',     icon:'fa-plus',           cls:'add'     },
    'po_received':   { label:'PO Received',     icon:'fa-truck',          cls:'add'     },
    'manual_adjust': { label:'Manual Adjust',   icon:'fa-sliders',        cls:'neutral' },
};

function timeAgo(dateStr) {
    const d    = new Date(dateStr.replace(' ', 'T'));
    const diff = Date.now() - d.getTime();
    const mins = Math.floor(diff / 60000);
    const hrs  = Math.floor(mins / 60);
    const days = Math.floor(hrs / 24);
    if (mins < 2)  return 'just now';
    if (mins < 60) return `${mins}m ago`;
    if (hrs  < 24) return `${hrs}h ago`;
    if (days < 7)  return `${days}d ago`;
    return d.toLocaleDateString('en-GB', { day:'numeric', month:'short', year:'numeric' });
}

async function openHistory(id, name) {
    document.getElementById('historySubtitle').textContent = name;
    document.getElementById('historyBody').innerHTML = '<div class="h-loading"><i class="fa-solid fa-spinner fa-spin"></i> Loading…</div>';
    document.getElementById('historyOverlay').classList.add('open');
    document.addEventListener('keydown', _historyEsc);

    try {
        const res  = await fetch(`ingredients.php?action=history&id=${id}`, { cache:'no-store' });
        const data = await res.json();
        if (!data.ok || !data.history.length) {
            document.getElementById('historyBody').innerHTML = '<div class="h-empty"><i class="fa-solid fa-inbox" style="font-size:28px;display:block;margin-bottom:10px"></i>No history yet</div>';
            return;
        }
        document.getElementById('historyBody').innerHTML = data.history.map(e => {
            const cfg  = TYPE_CFG[e.type] || { label:e.type, icon:'fa-circle', cls:'neutral' };
            const amt  = e.amount;
            // For manual_adjust, actual sign tells direction
            const actualCls = e.type === 'manual_adjust'
                ? (amt >= 0 ? 'pos' : 'neg')
                : (cfg.cls === 'add' ? 'pos' : (cfg.cls === 'remove' ? 'neg' : 'neu'));
            const sign = amt > 0 ? '+' : '';
            const fmtAmt = n => { const s = parseFloat(n).toFixed(4); return s.replace(/\.?0+$/, ''); };
            const ref  = e.reference ? `<div class="h-ref">${e.reference}</div>` : '';
            const by   = e.by ? `by ${e.by} · ` : '';
            return `<div class="h-entry">
                <div class="h-icon ${cfg.cls}"><i class="fa-solid ${cfg.icon}"></i></div>
                <div class="h-content">
                    <div class="h-type">${cfg.label}</div>
                    <div class="h-amt ${actualCls}">${sign}${fmtAmt(amt)}</div>
                    ${ref}
                    <div class="h-meta">${by}${timeAgo(e.at)}</div>
                </div>
            </div>`;
        }).join('');
    } catch {
        document.getElementById('historyBody').innerHTML = '<div class="h-empty">Failed to load history.</div>';
    }
}

function closeHistory() {
    document.getElementById('historyOverlay').classList.remove('open');
    document.removeEventListener('keydown', _historyEsc);
}

function _historyEsc(e) { if (e.key === 'Escape') closeHistory(); }

function refreshStatCards() {
    const allRows = [...document.querySelectorAll('#tableBody tr[data-status]')];
    const ok    = allRows.filter(r => r.dataset.status === 'ok').length;
    const low   = allRows.filter(r => r.dataset.status === 'low').length;
    const out   = allRows.filter(r => r.dataset.status === 'out').length;
    const total = allRows.length;
    let totalVal = 0;
    allRows.forEach(r => { totalVal += parseFloat(r.children[5]?.dataset?.val || 0); });

    const nums = document.querySelectorAll('.stat-num');
    if (nums[0]) nums[0].textContent = total;
    if (nums[1]) nums[1].textContent = ok;
    if (nums[2]) nums[2].textContent = low;
    if (nums[3]) nums[3].textContent = out;
    if (nums[4]) nums[4].textContent = fmtMoney(totalVal);

    const pillOk  = document.querySelector('[data-filter="ok"] .pill-count');
    const pillLow = document.querySelector('[data-filter="low"] .pill-count');
    const pillOut = document.querySelector('[data-filter="out"] .pill-count');
    if (pillOk)  pillOk.textContent  = ok;
    if (pillLow) pillLow.textContent = low;
    if (pillOut) pillOut.textContent = out;

    const footLabel = document.getElementById('footLabel');
    if (footLabel) footLabel.textContent = `${total} ingredients total`;
}

// initial JS pagination render
lastFilteredIng = [...document.querySelectorAll('#tableBody tr[data-name]')];
renderPageIng();
</script>
<script src="animations.js"></script>
</body>
</html>
