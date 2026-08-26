<?php
require 'auth.php';
require 'config.php';
header("Location: dashboard.php");
exit;

/* ── Business date (before 6 AM = yesterday) ── */
$now_h  = (int)date('G');
$bdate  = ($now_h < 6) ? date('Y-m-d', strtotime('-1 day')) : date('Y-m-d');
$bdate  = trim($_GET['date'] ?? $bdate);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bdate)) $bdate = date('Y-m-d');
$today  = date('Y-m-d');
$is_today = ($bdate === $today || ($now_h < 6 && $bdate === date('Y-m-d', strtotime('-1 day'))));

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ══════════════════════════════════════════════
   AJAX: save a single row's actual_qty
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_item') {
    header('Content-Type: application/json');
    $count_id      = (int)($_POST['count_id']      ?? 0);
    $ingredient_id = (int)($_POST['ingredient_id'] ?? 0);
    $actual        = $_POST['actual_qty'] ?? null;
    if ($count_id <= 0 || $ingredient_id <= 0 || !is_numeric($actual)) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid input']); exit;
    }
    $actual  = (float)$actual;
    // fetch expected qty (fast-fail if already submitted)
    $chk = $conn->prepare("
        SELECT sci.item_id, sci.expected_qty
        FROM stock_count_items sci
        JOIN stock_counts sc ON sc.count_id = sci.count_id
        WHERE sci.count_id=? AND sci.ingredient_id=? AND sc.status='draft'
    ");
    $chk->bind_param("ii", $count_id, $ingredient_id);
    $chk->execute();
    $item = $chk->get_result()->fetch_assoc();
    $chk->close();
    if (!$item) { echo json_encode(['ok'=>false,'msg'=>'Locked or not found']); exit; }
    $variance = $actual - (float)$item['expected_qty'];
    // JOIN on sc.status='draft' makes the lock-check atomic with the write (fixes TOCTOU)
    $upd = $conn->prepare("
        UPDATE stock_count_items sci
        JOIN stock_counts sc ON sc.count_id = sci.count_id AND sc.status = 'draft'
        SET sci.actual_qty=?, sci.variance=?
        WHERE sci.item_id=?
    ");
    $upd->bind_param("ddi", $actual, $variance, $item['item_id']);
    $upd->execute();
    if ($upd->affected_rows === 0) {
        $upd->close();
        echo json_encode(['ok'=>false,'msg'=>'Count was locked by another user']); exit;
    }
    $upd->close();
    echo json_encode(['ok'=>true,'variance'=>round($variance,2)]);
    exit;
}

/* ══════════════════════════════════════════════
   AJAX: submit (lock) the count
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit') {
    header('Content-Type: application/json');
    $count_id = (int)($_POST['count_id'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    if ($count_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid']); exit; }
    $by = $_SESSION['username'] ?? '';
    $s = $conn->prepare("UPDATE stock_counts SET status='submitted', submitted_by=?, submitted_at=NOW(), notes=? WHERE count_id=? AND status='draft'");
    $s->bind_param("ssi", $by, $notes, $count_id);
    $s->execute();
    if ($s->affected_rows === 0) {
        echo json_encode(['ok'=>false,'msg'=>'Already submitted or not found']); exit;
    }
    echo json_encode(['ok'=>true]);
    exit;
}

/* ══════════════════════════════════════════════
   AJAX: reconcile — apply a submitted count to stock
   (manager/admin only; overwrites ingredients.stock_quantity)
══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reconcile') {
    header('Content-Type: application/json');

    // Role gate: counter cannot approve own count; separate from stock_count perm
    if (!in_array($_SESSION['role'] ?? '', ['admin','manager'], true)) {
        echo json_encode(['ok'=>false,'msg'=>'Not authorized to apply counts.']); exit;
    }
    // CSRF
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid session token. Reload and try again.']); exit;
    }
    $count_id = (int)($_POST['count_id'] ?? 0);
    if ($count_id <= 0) { echo json_encode(['ok'=>false,'msg'=>'Invalid count.']); exit; }
    $by = $_SESSION['username'] ?? '';

    $conn->begin_transaction();
    try {
        // 1) Atomically claim: only if submitted + not yet reconciled (TOCTOU-safe,
        //    mirrors the submit guard at stock_count.php:68). Single-writer POS —
        //    a multi-server deploy would need SELECT ... FOR UPDATE on ingredients.
        $claim = $conn->prepare("UPDATE stock_counts
            SET reconciled_at=NOW(), reconciled_by=?
            WHERE count_id=? AND status='submitted' AND reconciled_at IS NULL");
        $claim->bind_param("si", $by, $count_id);
        $claim->execute();
        if ($claim->affected_rows === 0) {
            $conn->rollback();
            echo json_encode(['ok'=>false,'msg'=>'Already reconciled or not submitted.']); exit;
        }
        $claim->close();

        // fetch business_date for the log reference
        $bd_q = $conn->prepare("SELECT business_date FROM stock_counts WHERE count_id=?");
        $bd_q->bind_param("i", $count_id);
        $bd_q->execute();
        $business_date = $bd_q->get_result()->fetch_assoc()['business_date'] ?? '';
        $bd_q->close();

        // 2) Per counted item: overwrite stock, log signed delta
        $items_q = $conn->prepare("
            SELECT sci.ingredient_id, sci.expected_qty, sci.actual_qty, i.stock_quantity
            FROM stock_count_items sci
            JOIN ingredients i ON i.ingredient_id = sci.ingredient_id
            WHERE sci.count_id=? AND sci.actual_qty IS NOT NULL");
        $items_q->bind_param("i", $count_id);
        $items_q->execute();
        $rows = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);
        $items_q->close();

        $upd = $conn->prepare("UPDATE ingredients SET stock_quantity=? WHERE ingredient_id=?");
        $log = $conn->prepare("INSERT INTO ingredient_history
            (ingredient_id, change_type, amount, order_id, reference, created_by)
            VALUES (?, 'count_adjust', ?, NULL, ?, ?)");

        $adjusted = 0; $skipped = 0;
        foreach ($rows as $r) {
            $iid      = (int)$r['ingredient_id'];
            $newStock = (int)round((float)$r['actual_qty']);
            $oldStock = (int)$r['stock_quantity'];
            $delta    = $newStock - $oldStock;
            if ($delta === 0) { $skipped++; continue; }

            $upd->bind_param("ii", $newStock, $iid);
            $upd->execute();

            $ref = sprintf(
                'Stock count %s #%d: expected %s, counted %s (was %d, now %d)',
                $business_date, $count_id,
                rtrim(rtrim(number_format((float)$r['expected_qty'], 4, '.', ''), '0'), '.'),
                rtrim(rtrim(number_format((float)$r['actual_qty'],   4, '.', ''), '0'), '.'),
                $oldStock, $newStock
            );
            $amt = (float)$delta; // SIGNED per convention
            $log->bind_param("idss", $iid, $amt, $ref, $by);
            $log->execute();
            $adjusted++;
        }
        $upd->close();
        $log->close();

        $conn->commit();
        echo json_encode(['ok'=>true,'adjusted'=>$adjusted,'skipped'=>$skipped]);
    } catch (\Throwable $e) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'msg'=>'Apply failed — nothing changed.']);
    }
    exit;
}

/* ══════════════════════════════════════════════
   LOAD OR CREATE count session for $bdate
══════════════════════════════════════════════ */
$sc = $conn->prepare("SELECT * FROM stock_counts WHERE business_date=? LIMIT 1");
$sc->bind_param("s", $bdate);
$sc->execute();
$session = $sc->get_result()->fetch_assoc();

if (!$session) {
    // Create new draft session
    $by = $_SESSION['username'] ?? '';
    $ins = $conn->prepare("INSERT INTO stock_counts (business_date, status, created_by) VALUES (?,  'draft', ?)");
    $ins->bind_param("ss", $bdate, $by);
    $ins->execute();
    $count_id = $conn->insert_id;

    // Populate items: all ingredients
    $ings = $conn->query("SELECT ingredient_id, ingredient_name, stock_quantity FROM ingredients ORDER BY ingredient_name ASC");
    if ($ings) {
        while ($ing = $ings->fetch_assoc()) {
            $iid = (int)$ing['ingredient_id'];
            $opening = (float)$ing['stock_quantity'];
            $expected = $opening;
            $used_today = 0.0;
            $ii = $conn->prepare("INSERT INTO stock_count_items (count_id, ingredient_id, opening_stock, system_used, expected_qty) VALUES (?,?,?,?,?)");
            $ii->bind_param("iiddd", $count_id, $iid, $opening, $used_today, $expected);
            $ii->execute();
        }
    }

    $sc2 = $conn->prepare("SELECT * FROM stock_counts WHERE count_id=? LIMIT 1");
    $sc2->bind_param("i", $count_id);
    $sc2->execute();
    $session = $sc2->get_result()->fetch_assoc();
}

$count_id  = (int)$session['count_id'];
$is_locked = ($session['status'] === 'submitted');
$is_manager     = in_array($_SESSION['role'] ?? '', ['admin','manager'], true);
$is_reconciled  = !empty($session['reconciled_at']);

/* ── Load items ── */
$items_q = $conn->prepare("
    SELECT sci.*, i.ingredient_name, i.unit, i.minimum_stock
    FROM stock_count_items sci
    JOIN ingredients i ON i.ingredient_id = sci.ingredient_id
    WHERE sci.count_id = ?
    ORDER BY i.ingredient_name ASC
");
$items_q->bind_param("i", $count_id);
$items_q->execute();
$items = $items_q->get_result()->fetch_all(MYSQLI_ASSOC);

/* ── Summary stats ── */
$total_items    = count($items);
$counted        = array_filter($items, fn($r) => $r['actual_qty'] !== null);
$counted_count  = count($counted);
$missing        = array_filter($items, fn($r) => $r['variance'] !== null && $r['variance'] < -0.01);
$over           = array_filter($items, fn($r) => $r['variance'] !== null && $r['variance'] > 0.01);
$ok             = array_filter($items, fn($r) => $r['variance'] !== null && abs($r['variance']) <= 0.01);

$date_fmt = date('M j, Y', strtotime($bdate));
$day_label = ($bdate === $today) ? 'Today' : (($bdate === date('Y-m-d', strtotime('-1 day'))) ? 'Yesterday' : $date_fmt);
?>
<!DOCTYPE html>
<html lang="<?= current_lang() ?>" data-lang="<?= current_lang() ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Count — <?= h($date_fmt) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Kantumruy+Pro:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&family=Noto+Sans+Khmer:wght@300;400;500;600;700;800&family=Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400;1,600;1,700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){try{if(localStorage.getItem("theme")==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#0a0a0a;--surface:#111;--surface2:#161616;--border:rgba(255,255,255,.07);
  --amber:#d1904b;--amber-dim:rgba(209,144,75,.12);--amber-border:rgba(209,144,75,.2);
  --green:#22c55e;--green-dim:rgba(34,197,94,.1);--green-border:rgba(34,197,94,.2);
  --red:#ef4444;--red-dim:rgba(239,68,68,.1);--red-border:rgba(239,68,68,.2);
  --blue:#60a5fa;
  --text:#f0f0f0;--muted:#555;--muted2:#888;
  --radius:14px;
}

body, input, select, textarea, button, table {
  font-family: 'Poppins', 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}
:lang(km), [data-lang="km"], html[lang="km"], html[lang="km"] * {
  font-family: 'Kantumruy Pro', 'Noto Sans Khmer', 'Siemreap', 'Khmer OS Battambang', 'Khmer OS Siemreap', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important;
}
html[lang="km"] .fa, html[lang="km"] [class*="fa-"], html[lang="km"] i {
  font-family: 'Font Awesome 6 Free', 'FontAwesome' !important;
}
html[lang="km"] .fa-brands, html[lang="km"] [class*="fa-brands"] {
  font-family: 'Font Awesome 6 Brands', 'FontAwesome' !important;
}

@keyframes fadeInUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
@keyframes scaleIn  { from{opacity:0;transform:scale(.93)}        to{opacity:1;transform:scale(1)}     }
@keyframes fadeIn   { from{opacity:0}                             to{opacity:1}                        }
@keyframes spin     { to{transform:rotate(360deg)} }

body{
  background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.07) 0%,transparent 100%),#0a0a0a;
  color:var(--text);min-height:100vh;
}
body.fading{opacity:0;pointer-events:none;transition:opacity .4s ease;}
a{text-decoration:none;}

/* ── Topbar ── */
.topbar{
  display:flex;align-items:center;justify-content:space-between;
  padding:16px 28px;
  background:rgba(255,255,255,.02);
  border-bottom:1px solid var(--border);
  animation:fadeIn .4s ease both;
}
.topbar-left{display:flex;align-items:center;gap:14px;}
.back-btn{
  display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:10px;
  background:rgba(209,144,75,.08);border:1px solid rgba(209,144,75,.35);
  color:#d1904b;font-size:13px;font-weight:600;text-decoration:none;transition:all .2s;
}
.back-btn:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}
.page-title{font-size:18px;font-weight:700;color:var(--text);}
.page-sub{font-size:12px;color:var(--muted);margin-top:1px;}
.page-sub strong{color:var(--amber);}

/* ── Wrap ── */
.wrap{max-width:960px;margin:0 auto;padding:28px 24px 80px;}

/* ── Date nav ── */
.date-nav{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:24px;animation:fadeIn .4s ease both;}
.date-nav a{
  display:inline-flex;align-items:center;gap:6px;
  padding:7px 14px;border-radius:9px;font-size:12px;font-weight:500;
  background:rgba(255,255,255,.05);border:1px solid var(--border);
  color:var(--muted2);transition:background .2s,color .2s;
}
.date-nav a:hover{background:rgba(255,255,255,.09);color:var(--text);}
.date-nav .current-date{
  font-size:13px;font-weight:600;color:var(--text);padding:7px 16px;
  background:var(--amber-dim);border:1px solid var(--amber-border);border-radius:9px;
}

/* ── Stats ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;}
@media(max-width:600px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
.stat-card{
  background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);
  padding:20px 22px;text-align:center;
  animation:scaleIn .45s cubic-bezier(.16,1,.3,1) both;
  transition:transform .2s,border-color .2s;
}
.stat-card:hover{transform:translateY(-2px);}
.stat-card:nth-child(1){animation-delay:.08s}
.stat-card:nth-child(2){animation-delay:.14s}
.stat-card:nth-child(3){animation-delay:.20s}
.stat-card:nth-child(4){animation-delay:.26s}
.stat-card .val{font-size:30px;font-weight:800;line-height:1;margin-bottom:4px;}
.stat-card .lbl{font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.stat-card.c-total{border-color:rgba(96,165,250,.2);background:rgba(96,165,250,.06);}
.stat-card.c-total .val{color:var(--blue);}
.stat-card.c-ok   {border-color:var(--green-border);background:var(--green-dim);}
.stat-card.c-ok   .val{color:var(--green);}
.stat-card.c-miss {border-color:var(--red-border);background:var(--red-dim);}
.stat-card.c-miss .val{color:var(--red);}
.stat-card.c-over {border-color:var(--amber-border);background:var(--amber-dim);}
.stat-card.c-over .val{color:var(--amber);}

/* ── Section ── */
.section{
  background:var(--surface);border:1px solid var(--border);
  border-radius:var(--radius);overflow:hidden;margin-bottom:16px;
  animation:fadeInUp .45s ease both;
}
.section.s1{animation-delay:.32s}
.section.s2{animation-delay:.40s}
.section-inner{padding:20px;}
.section-title{
  font-size:11px;font-weight:600;color:var(--muted2);letter-spacing:.9px;text-transform:uppercase;
  margin-bottom:16px;display:flex;align-items:center;gap:7px;
}

/* ── Locked banner ── */
.locked-banner{
  background:var(--green-dim);border:1px solid var(--green-border);
  border-radius:var(--radius);padding:12px 18px;margin-bottom:16px;
  display:flex;align-items:center;gap:10px;
  font-size:13px;color:#86efac;
  animation:fadeIn .4s ease both;
}
.locked-banner i{color:var(--green);font-size:15px;}

/* ── Table ── */
.count-table{width:100%;border-collapse:collapse;}
.count-table th{
  font-size:11px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;
  color:var(--muted);padding:11px 16px;text-align:right;
  border-bottom:1px solid var(--border);background:var(--surface2);
}
.count-table th:first-child{text-align:left;}
.count-table th.col-actual{color:var(--amber);}
.count-table tr.item-row{border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;}
.count-table tr.item-row:last-child{border-bottom:none;}
.count-table tr.item-row:hover{background:rgba(255,255,255,.03);}
.count-table td{padding:13px 16px;font-size:13px;text-align:right;vertical-align:middle;}
.count-table td:first-child{text-align:left;}
.ing-name{font-weight:500;color:var(--text);}
.ing-unit{font-size:11px;color:var(--muted);margin-top:2px;}
.num-cell{font-variant-numeric:tabular-nums;color:var(--muted2);}
.num-cell.expected{color:#ccc;font-weight:500;}

/* ── Actual input ── */
.actual-wrap{display:flex;justify-content:flex-end;align-items:center;gap:6px;}
.actual-input{
  width:90px;padding:6px 10px;text-align:right;
  background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);
  border-radius:8px;color:var(--text);font-size:13px;font-family:inherit;font-weight:500;
  transition:border-color .2s,background .2s;
}
.actual-input:focus{outline:none;border-color:rgba(209,144,75,.6);background:var(--amber-dim);}
.actual-input:disabled{opacity:.5;cursor:not-allowed;}
.save-indicator{width:18px;height:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.save-indicator .spinner{width:14px;height:14px;border:2px solid rgba(255,255,255,.15);border-top-color:var(--amber);border-radius:50%;animation:spin .7s linear infinite;}
.save-indicator .ok-icon{color:var(--green);font-size:13px;opacity:0;transition:opacity .3s;}
.save-indicator .ok-icon.show{opacity:1;}

/* ── Variance badge ── */
.var-badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.var-badge.ok   {background:var(--green-dim);color:#86efac;border:1px solid var(--green-border);}
.var-badge.miss {background:var(--red-dim);color:#fca5a5;border:1px solid var(--red-border);}
.var-badge.over {background:var(--amber-dim);color:#fbbf24;border:1px solid var(--amber-border);}
.var-badge.empty{background:rgba(255,255,255,.04);color:var(--muted);}

/* ── Status pill ── */
.status-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;}
.status-pill.ok    {background:var(--green-dim);color:#86efac;border:1px solid var(--green-border);}
.status-pill.review{background:var(--red-dim);color:#fca5a5;border:1px solid var(--red-border);}
.status-pill.low   {background:var(--amber-dim);color:#fbbf24;border:1px solid var(--amber-border);}
.status-pill.empty {background:rgba(255,255,255,.04);color:var(--muted);}

/* ── Progress ── */
.progress-wrap{margin-bottom:20px;}
.progress-label{display:flex;justify-content:space-between;align-items:center;font-size:12px;color:var(--muted2);margin-bottom:7px;}
.progress-label strong{color:var(--amber);}
.progress-bar-bg{background:rgba(255,255,255,.06);border-radius:6px;height:6px;overflow:hidden;}
.progress-bar{height:100%;background:linear-gradient(90deg,var(--amber),#f5a623);border-radius:6px;transition:width .6s ease;}

/* ── Notes / submit ── */
.notes-row{display:flex;gap:10px;align-items:flex-end;margin-top:16px;padding-top:16px;border-top:1px solid var(--border);}
.notes-input{
  flex:1;padding:9px 12px;
  background:rgba(255,255,255,.06);border:1px solid var(--border);
  border-radius:10px;color:var(--text);font-size:13px;font-family:inherit;resize:none;
  transition:border-color .2s;
}
.notes-input:focus{outline:none;border-color:rgba(209,144,75,.5);}
.notes-input:disabled{opacity:.5;cursor:not-allowed;}
.submit-btn{
  padding:10px 22px;border-radius:10px;font-size:13px;font-weight:700;
  background:var(--amber);color:#000;
  border:none;cursor:pointer;font-family:inherit;
  transition:opacity .2s,transform .1s;
  display:inline-flex;align-items:center;gap:7px;white-space:nowrap;
}
.submit-btn:hover:not(:disabled){opacity:.85;transform:translateY(-1px);}
.submit-btn:disabled{opacity:.4;cursor:not-allowed;transform:none;}

/* ── Mobile ── */
@media(max-width:600px){
  .topbar{padding:12px 16px;}
  .wrap{padding:20px 14px 60px;}
  .count-table th.col-open,.count-table th.col-used,
  .count-table td.col-open,.count-table td.col-used{display:none;}
  .actual-input{width:76px;}
}

/* ── Pagination ── */
.pg-wrap{display:flex;align-items:center;justify-content:center;gap:4px;padding:16px 20px;}
.pg-btn{
  min-width:34px;height:34px;padding:0 10px;
  background:rgba(255,255,255,.06);border:1px solid var(--border);
  border-radius:8px;color:var(--muted2);font-size:12px;font-weight:600;
  cursor:pointer;font-family:inherit;transition:background .15s,color .15s,border-color .15s;
  display:inline-flex;align-items:center;justify-content:center;
}
.pg-btn:hover:not(:disabled){background:rgba(255,255,255,.11);color:var(--text);}
.pg-btn:disabled{opacity:.28;cursor:not-allowed;}
.pg-btn.active{background:var(--amber);color:#000;border-color:var(--amber);}
.pg-ellipsis{color:var(--muted);font-size:13px;padding:0 4px;line-height:34px;}
/* Light theme (follows shared localStorage theme) */
[data-theme="light"]{--bg:#ECEEF2;--surface:#FFFFFF;--surface2:#F5F7FA;--border:#E2E5EA;--text:#111827;--muted:#5A6373;--muted2:#5A6373;}
[data-theme="light"] .topbar{background:rgba(255,255,255,.92);}
[data-theme="light"] body{background:radial-gradient(ellipse 80% 40% at 50% 0%,rgba(209,144,75,.05) 0%,transparent 100%),#ECEEF2;}
[data-theme="light"] .filter-group select option,
[data-theme="light"] select option{background:#FFFFFF;}
</style>
</head>
<body>

<!-- Topbar -->
<div class="topbar">
    <div class="topbar-left">
        <a href="dashboard.php" class="back-btn" onclick="document.body.classList.add('fading')">
            <i class="fa-solid fa-arrow-left"></i> Back
        </a>
        <div>
            <div class="page-title"><i class="fa-solid fa-clipboard-list" style="color:var(--amber);margin-right:8px"></i>Stock Count</div>
            <div class="page-sub">
                Logged in as <strong><?= h($_SESSION['username'] ?? '') ?></strong>
                <?php if ($is_locked): ?>
                · Submitted by <strong><?= h($session['submitted_by'] ?? '') ?></strong>
                at <strong><?= h(date('g:i A', strtotime($session['submitted_at']))) ?></strong>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:12px">
        <?php /* Prints whatever has been entered for this date. Offered on a draft
                 too, because a clerk counting a long shelf reasonably wants the
                 sheet in hand — the PDF stamps DRAFT across it so nobody approves
                 a write-off from an unfinished count. */ ?>
        <a href="stock_count_pdf.php?date=<?= h($bdate) ?>" target="_blank" rel="noopener"
           class="back-btn" title="Export this count as PDF">
            <i class="fa-solid fa-file-pdf"></i> PDF
        </a>
        <div style="font-size:12px;color:var(--muted)"><?= h($date_fmt) ?></div>
    </div>
</div>

<div class="wrap">

<!-- Date navigation -->
<?php
$prev = date('Y-m-d', strtotime($bdate . ' -1 day'));
$next = date('Y-m-d', strtotime($bdate . ' +1 day'));
?>
<div class="date-nav">
    <a href="?date=<?= $prev ?>"><i class="fa-solid fa-chevron-left"></i> <?= date('M j', strtotime($prev)) ?></a>
    <span class="current-date"><?= h($day_label === 'Today' || $day_label === 'Yesterday' ? $day_label : $date_fmt) ?></span>
    <?php if ($bdate < $today): ?>
    <a href="?date=<?= $next ?>"><?= date('M j', strtotime($next)) ?> <i class="fa-solid fa-chevron-right"></i></a>
    <?php else: ?>
    <span style="opacity:.25;font-size:12px;padding:7px 8px">—</span>
    <?php endif; ?>
</div>

<?php if ($is_locked): ?>
<div class="locked-banner">
    <i class="fa-solid fa-circle-check"></i>
    Count submitted and locked. View-only mode.
</div>
    <?php if ($is_reconciled): ?>
    <div class="locked-banner" style="background:rgba(96,165,250,.1);border-color:rgba(96,165,250,.25);color:#93c5fd;">
        <i class="fa-solid fa-clipboard-check" style="color:#60a5fa;"></i>
        Reconciled by <strong style="color:#bfdbfe;">&nbsp;<?= h($session['reconciled_by'] ?? '') ?>&nbsp;</strong>
        at <strong style="color:#bfdbfe;">&nbsp;<?= h(date('g:i A, M j', strtotime($session['reconciled_at']))) ?></strong>
    </div>
    <?php elseif ($is_manager && $counted_count > 0): ?>
    <div class="locked-banner" style="background:var(--amber-dim);border-color:var(--amber-border);color:#fbbf24;justify-content:space-between;">
        <span><i class="fa-solid fa-scale-balanced" style="color:var(--amber);"></i>
            Review complete? Apply these counts to system stock.</span>
        <button class="submit-btn" id="applyBtn" onclick="applyReconcile()" style="padding:8px 18px;">
            <i class="fa-solid fa-check-double"></i> Apply to Stock
        </button>
    </div>
    <?php elseif ($is_manager): ?>
    <div class="locked-banner" style="background:rgba(255,255,255,.04);border-color:var(--border);color:var(--muted2);">
        <i class="fa-solid fa-circle-info"></i> No counted items to apply.
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Stats -->
<div class="stat-grid">
    <div class="stat-card c-total">
        <div class="val"><?= $total_items ?></div>
        <div class="lbl">Total Items</div>
    </div>
    <div class="stat-card c-ok">
        <div class="val"><?= count($ok) ?></div>
        <div class="lbl">Matched</div>
    </div>
    <div class="stat-card c-miss">
        <div class="val"><?= count($missing) ?></div>
        <div class="lbl">Shortage</div>
    </div>
    <div class="stat-card c-over">
        <div class="val"><?= count($over) ?></div>
        <div class="lbl">Overage</div>
    </div>
</div>

<!-- Main count table -->
<div class="section s1">
    <div class="section-inner">
        <div class="section-title">
            <i class="fa-solid fa-boxes-stacked"></i>
            INVENTORY COUNT — <?= h(strtoupper($day_label !== 'Today' && $day_label !== 'Yesterday' ? $date_fmt : $day_label)) ?>
        </div>

        <!-- Progress bar -->
        <?php if (!$is_locked): ?>
        <div class="progress-wrap">
            <div class="progress-label">
                <span>Progress</span>
                <strong><?= $counted_count ?> / <?= $total_items ?> counted</strong>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-bar" id="progressBar" style="width:<?= $total_items > 0 ? round($counted_count/$total_items*100) : 0 ?>%"></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <table class="count-table">
        <thead>
            <tr>
                <th style="width:30%">Item</th>
                <th class="col-open">Opening</th>
                <th class="col-used">Used Today</th>
                <th>Expected</th>
                <th class="col-actual">Actual Count</th>
                <th>Variance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($items as $row):
            $ing_id    = (int)$row['ingredient_id'];
            $opening   = (float)$row['opening_stock'];
            $used      = (float)$row['system_used'];
            $expected  = (float)$row['expected_qty'];
            $actual    = $row['actual_qty'];
            $variance  = $row['variance'];
            $unit      = h($row['unit']);

            // Status logic
            if ($actual === null) {
                $status_html = '<span class="status-pill empty">—</span>';
            } elseif (abs((float)$variance) <= 0.01) {
                $status_html = '<span class="status-pill ok"><i class="fa-solid fa-check"></i> OK</span>';
            } elseif ((float)$variance < -((float)$row['minimum_stock'] * 0.1 + 1)) {
                $status_html = '<span class="status-pill review"><i class="fa-solid fa-triangle-exclamation"></i> REVIEW</span>';
            } elseif ((float)$variance < 0) {
                $status_html = '<span class="status-pill low"><i class="fa-solid fa-minus"></i> Low</span>';
            } else {
                $status_html = '<span class="status-pill ok"><i class="fa-solid fa-plus"></i> Over</span>';
            }

            if ($variance === null) {
                $var_html = '<span class="var-badge empty">—</span>';
            } elseif (abs((float)$variance) <= 0.01) {
                $var_html = '<span class="var-badge ok">0</span>';
            } elseif ((float)$variance < 0) {
                $var_html = '<span class="var-badge miss">'.number_format((float)$variance, 1).' '.$unit.'</span>';
            } else {
                $var_html = '<span class="var-badge over">+'.number_format((float)$variance, 1).' '.$unit.'</span>';
            }
        ?>
        <tr class="item-row" data-id="<?= $ing_id ?>">
            <td>
                <div class="ing-name"><?= h($row['ingredient_name']) ?></div>
                <div class="ing-unit"><?= $unit ?></div>
            </td>
            <td class="num-cell col-open"><?= number_format($opening, 1) ?></td>
            <td class="num-cell col-used" style="color:<?= $used > 0 ? '#f87171' : '#555' ?>">
                <?php /* No leading minus: the column already says these units were used up,
                         so "−40.0" under "Used Today" reads as a double negative. The red
                         keeps the drawdown cue. */ ?>
                <?= $used > 0 ? number_format($used, 1) : '—' ?>
            </td>
            <td class="num-cell expected"><?= number_format($expected, 1) ?></td>
            <td>
                <div class="actual-wrap">
                    <input
                        type="number"
                        class="actual-input"
                        data-id="<?= $ing_id ?>"
                        data-count="<?= $count_id ?>"
                        value="<?= $actual !== null ? number_format((float)$actual, 1, '.', '') : '' ?>"
                        placeholder="—"
                        step="0.1"
                        min="0"
                        <?= $is_locked ? 'disabled' : '' ?>
                    >
                    <div class="save-indicator" id="si-<?= $ing_id ?>">
                        <i class="fa-solid fa-check ok-icon" id="ok-<?= $ing_id ?>"></i>
                    </div>
                </div>
            </td>
            <td id="var-<?= $ing_id ?>"><?= $var_html ?></td>
            <td id="st-<?= $ing_id ?>"><?= $status_html ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div id="pagination"></div>

    <div class="section-inner" style="padding-top:0">
    <?php if (!$is_locked): ?>
    <div class="notes-row">
        <textarea
            id="countNotes"
            class="notes-input"
            rows="2"
            placeholder="Notes (optional) — e.g. fridge temp, staff on shift…"
        ><?= h($session['notes'] ?? '') ?></textarea>
        <button class="submit-btn" id="submitBtn" onclick="submitCount()">
            <i class="fa-solid fa-circle-check"></i>
            Submit Count
        </button>
    </div>
    <?php elseif ($session['notes']): ?>
    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);font-size:13px;color:var(--muted2);">
        <span style="font-size:10px;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-right:8px;">Notes</span>
        <?= h($session['notes']) ?>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- Variance summary (only after any counted) -->
<?php
$counted_arr = array_values(array_filter($items, fn($r) => $r['actual_qty'] !== null && abs((float)$r['variance']) > 0.01));
if ($counted_arr):
?>
<div class="section s2">
    <div class="section-inner">
        <div class="section-title">
            <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b"></i>
            VARIANCES
        </div>
    </div>
    <table class="count-table">
        <thead>
            <tr>
                <th>Item</th>
                <th>Expected</th>
                <th>Actual</th>
                <th>Variance</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($counted_arr as $row):
            $variance = (float)$row['variance'];
            $unit = h($row['unit']);
            $var_str = ($variance >= 0 ? '+' : '').number_format($variance, 1).' '.$unit;
            $var_class = abs($variance) <= 0.01 ? 'ok' : ($variance < 0 ? 'miss' : 'over');
        ?>
        <tr class="item-row">
            <td><div class="ing-name"><?= h($row['ingredient_name']) ?></div></td>
            <td class="num-cell"><?= number_format((float)$row['expected_qty'], 1) ?> <?= $unit ?></td>
            <td class="num-cell"><?= number_format((float)$row['actual_qty'], 1) ?> <?= $unit ?></td>
            <td><span class="var-badge <?= $var_class ?>"><?= $var_str ?></span></td>
            <td>
                <?php if ($variance < -((float)$row['minimum_stock'] * 0.1 + 1)): ?>
                <span class="status-pill review"><i class="fa-solid fa-triangle-exclamation"></i> REVIEW</span>
                <?php elseif ($variance < 0): ?>
                <span class="status-pill low"><i class="fa-solid fa-minus"></i> Shortage</span>
                <?php else: ?>
                <span class="status-pill ok"><i class="fa-solid fa-plus"></i> Overage</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

</div><!-- /.wrap -->

<script>
const COUNT_ID = <?= $count_id ?>;
const CSRF_TOKEN  = <?= json_encode($_SESSION['csrf_token']) ?>;
const COUNTED_NOW = <?= (int)$counted_count ?>;
const TOTAL    = <?= $total_items ?>;
let   counted  = <?= $counted_count ?>;

/* ── Debounced auto-save ── */
let timers = {};

document.querySelectorAll('.actual-input').forEach(inp => {
    inp.addEventListener('input', function() {
        const id = this.dataset.id;
        clearTimeout(timers[id]);
        showSpinner(id);
        timers[id] = setTimeout(() => saveItem(id, this.value), 600);
    });
    inp.addEventListener('change', function() {
        const id = this.dataset.id;
        clearTimeout(timers[id]);
        showSpinner(id);
        saveItem(id, this.value);
    });
});

function showSpinner(id) {
    const ok = document.getElementById('ok-' + id);
    if (ok) ok.classList.remove('show');
    const si = document.getElementById('si-' + id);
    if (si && !si.querySelector('.spinner')) {
        si.innerHTML = '<div class="spinner"></div>';
    }
}

function saveItem(ingredient_id, value) {
    if (value === '' || isNaN(parseFloat(value))) {
        clearIndicator(ingredient_id);
        clearVarianceCell(ingredient_id);
        return;
    }
    const fd = new FormData();
    fd.append('action', 'save_item');
    fd.append('count_id', COUNT_ID);
    fd.append('ingredient_id', ingredient_id);
    fd.append('actual_qty', parseFloat(value).toFixed(1));

    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                showOK(ingredient_id);
                updateVariance(ingredient_id, d.variance, parseFloat(value));
                updateProgress();
            }
        })
        .catch(() => clearIndicator(ingredient_id));
}

function showOK(id) {
    const si = document.getElementById('si-' + id);
    if (si) {
        si.innerHTML = '<i class="fa-solid fa-check ok-icon show" id="ok-' + id + '"></i>';
        setTimeout(() => {
            const ok = document.getElementById('ok-' + id);
            if (ok) ok.classList.remove('show');
        }, 2000);
    }
}

function clearIndicator(id) {
    const si = document.getElementById('si-' + id);
    if (si) si.innerHTML = '<i class="fa-solid fa-check ok-icon" id="ok-' + id + '"></i>';
}

function clearVarianceCell(id) {
    const varCell = document.getElementById('var-' + id);
    const stCell  = document.getElementById('st-'  + id);
    if (varCell) varCell.innerHTML = '<span class="var-badge empty">—</span>';
    if (stCell)  stCell.innerHTML  = '<span class="status-pill empty">—</span>';
    updateStatCards();
}

function updateVariance(id, variance, actual) {
    const varCell = document.getElementById('var-' + id);
    const stCell  = document.getElementById('st-' + id);
    if (!varCell || !stCell) return;

    const abs = Math.abs(variance);
    let varHtml, stHtml;

    const unitEl = document.querySelector('[data-id="' + id + '"]')
                    ?.closest('tr')?.querySelector('.ing-unit');
    const unit   = unitEl ? unitEl.textContent.trim() : '';

    if (abs <= 0.01) {
        varHtml = '<span class="var-badge ok">0</span>';
        stHtml  = '<span class="status-pill ok"><i class="fa-solid fa-check"></i> OK</span>';
    } else if (variance < 0) {
        varHtml = `<span class="var-badge miss">${variance.toFixed(1)} ${unit}</span>`;
        stHtml  = '<span class="status-pill low"><i class="fa-solid fa-minus"></i> Shortage</span>';
    } else {
        varHtml = `<span class="var-badge over">+${variance.toFixed(1)} ${unit}</span>`;
        stHtml  = '<span class="status-pill ok"><i class="fa-solid fa-plus"></i> Over</span>';
    }

    varCell.innerHTML = varHtml;
    stCell.innerHTML  = stHtml;

    // Update stat cards
    updateStatCards();
}

function updateStatCards() {
    const rows = document.querySelectorAll('.item-row[data-id]');
    let ok = 0, miss = 0, over = 0, cnt = 0;
    rows.forEach(row => {
        const varBadge = row.querySelector('[id^="var-"]');
        if (!varBadge) return;
        const badge = varBadge.querySelector('.var-badge');
        if (!badge || badge.classList.contains('empty')) return;
        cnt++;
        if (badge.classList.contains('ok'))   ok++;
        if (badge.classList.contains('miss')) miss++;
        if (badge.classList.contains('over')) over++;
    });
    const cards = document.querySelectorAll('.stat-card');
    if (cards[1]) cards[1].querySelector('.val').textContent = ok;
    if (cards[2]) cards[2].querySelector('.val').textContent = miss;
    if (cards[3]) cards[3].querySelector('.val').textContent = over;
}

function updateProgress() {
    const inputs = document.querySelectorAll('.actual-input');
    let filled = 0;
    inputs.forEach(i => { if (i.value !== '') filled++; });
    const bar  = document.getElementById('progressBar');
    const lbl  = document.querySelector('.progress-label strong');
    if (bar) bar.style.width = Math.round(filled / TOTAL * 100) + '%';
    if (lbl) lbl.textContent = filled + ' / ' + TOTAL + ' counted';
}

/* ── Submit ── */
function submitCount() {
    const btn = document.getElementById('submitBtn');
    const notes = document.getElementById('countNotes')?.value || '';

    // Check at least half are counted
    const inputs = document.querySelectorAll('.actual-input');
    let filled = 0;
    inputs.forEach(i => { if (i.value !== '') filled++; });
    if (filled === 0) {
        alert('Please count at least one item before submitting.');
        return;
    }

    if (!confirm(`Submit this stock count for ${filled}/${TOTAL} items? This cannot be undone.`)) return;

    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></div> Submitting…';

    const fd = new FormData();
    fd.append('action', 'submit');
    fd.append('count_id', COUNT_ID);
    fd.append('notes', notes);

    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                document.body.classList.add('fading');
                setTimeout(() => location.reload(), 400);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Submit Count';
                alert('Error: ' + (d.msg || 'Unknown error'));
            }
        });
}

/* ── Pagination ── */
const PAGE_SIZE = 10;
let currentPage = 1;

function allRows() {
    return Array.from(document.querySelectorAll('.count-table tbody .item-row[data-id]'));
}

function goPage(page) {
    const rows = allRows();
    const totalPages = Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
    currentPage = Math.max(1, Math.min(page, totalPages));
    const start = (currentPage - 1) * PAGE_SIZE;
    const end   = start + PAGE_SIZE;
    rows.forEach((row, i) => { row.style.display = (i >= start && i < end) ? '' : 'none'; });
    renderPagination(totalPages);
}

function renderPagination(totalPages) {
    const pg = document.getElementById('pagination');
    if (!pg) return;
    if (totalPages <= 1) { pg.innerHTML = ''; return; }

    const range = 2;
    let html = '<div class="pg-wrap">';
    html += `<button class="pg-btn" onclick="goPage(1)" ${currentPage===1?'disabled':''}>«</button>`;
    html += `<button class="pg-btn" onclick="goPage(${currentPage-1})" ${currentPage===1?'disabled':''}>‹</button>`;

    let lastPrinted = 0;
    for (let i = 1; i <= totalPages; i++) {
        const inRange = (i === 1 || i === totalPages || (i >= currentPage - range && i <= currentPage + range));
        if (inRange) {
            if (lastPrinted && i - lastPrinted > 1) html += '<span class="pg-ellipsis">…</span>';
            html += `<button class="pg-btn${i===currentPage?' active':''}" onclick="goPage(${i})">${i}</button>`;
            lastPrinted = i;
        }
    }

    html += `<button class="pg-btn" onclick="goPage(${currentPage+1})" ${currentPage===totalPages?'disabled':''}>›</button>`;
    html += `<button class="pg-btn" onclick="goPage(${totalPages})" ${currentPage===totalPages?'disabled':''}>»</button>`;
    html += '</div>';
    pg.innerHTML = html;
}

/* ── Reconcile (manager/admin) ── */
function applyReconcile() {
    const btn = document.getElementById('applyBtn');
    if (!confirm(`Set system stock to the counted values for ${COUNTED_NOW} counted item(s)? `
              + `This adjusts inventory and is logged. Uncounted items are left unchanged.`)) return;
    btn.disabled = true;
    btn.innerHTML = '<div class="spinner" style="width:14px;height:14px;border:2px solid rgba(0,0,0,.2);border-top-color:#000;border-radius:50%;animation:spin .7s linear infinite"></div> Applying…';
    const fd = new FormData();
    fd.append('action', 'reconcile');
    fd.append('count_id', COUNT_ID);
    fd.append('csrf_token', CSRF_TOKEN);
    fetch('stock_count.php', { method:'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                alert(`Reconciled — ${d.adjusted} adjusted, ${d.skipped} unchanged.`);
                document.body.classList.add('fading');
                setTimeout(() => location.reload(), 400);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Apply to Stock';
                alert('Error: ' + (d.msg || 'Unknown error'));
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-check-double"></i> Apply to Stock';
            alert('Network error — nothing changed.');
        });
}

// Init pagination on load
goPage(1);
</script>
</body>
</html>
