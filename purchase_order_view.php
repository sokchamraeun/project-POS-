<?php
require 'auth.php';
require 'config.php';
if (!can('purchase_orders')) { header("Location: purchase_orders.php"); exit; }

$po_id = (int)($_GET['po_id'] ?? 0);
if ($po_id <= 0) { header('Location: purchase_orders.php'); exit; }

$created_msg = isset($_GET['created']) ? 'Purchase order created successfully.' : '';
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// These actions can be fired from the PO list as well as this page; send the user
// back where they came from so a one-click receive from the list stays on the list.
$from_list = ($_POST['return'] ?? '') === 'list';
function po_redirect(bool $from_list, int $po_id, string $msg): void {
    header($from_list
        ? "Location: purchase_orders.php?msg=$msg"
        : "Location: purchase_order_view.php?po_id=$po_id&msg=$msg");
    exit;
}

// ── HANDLE STATUS ACTIONS ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Every action below mutates stock or PO state.
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        po_redirect($from_list, $po_id, 'badtoken');
    }

    if ($action === 'mark_ordered') {
        $stmt = $conn->prepare("UPDATE purchase_orders SET status='Ordered', ordered_at=NOW() WHERE po_id=? AND status='Draft'");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) po_redirect($from_list, $po_id, 'nochange');
        po_redirect($from_list, $po_id, 'ordered');
    }

    if ($action === 'receive_items') {
        // Receiving is now per line and repeatable, so the old guard — claiming
        // the PO by flipping status='Ordered' and checking affected_rows — no
        // longer guards anything: the second receive of a partially delivered
        // order is legitimate. Each line is claimed individually instead,
        // against the qty_received the form was rendered with.
        $po_now = $conn->prepare("SELECT po_number, status FROM purchase_orders WHERE po_id=?");
        $po_now->bind_param('i', $po_id);
        $po_now->execute();
        $po_row = $po_now->get_result()->fetch_assoc();
        if (!$po_row || !in_array($po_row['status'], ['Ordered', 'Partially Received'], true)) {
            po_redirect($from_list, $po_id, 'nochange');
        }

        $recv = (array)($_POST['recv'] ?? []);
        $seen = (array)($_POST['seen'] ?? []);

        // A negative delivery is not a thing. Reject before opening the
        // transaction so nothing is half-applied.
        foreach ($recv as $qty) {
            if ($qty !== '' && (!is_numeric($qty) || (float)$qty < 0)) {
                po_redirect($from_list, $po_id, 'badqty');
            }
        }
        // A blank or non-numeric box means "none of this arrived", never "all
        // of it did" — the old code's assumption is what caused phantom stock.
        $moves = [];
        foreach ($recv as $poi_id => $qty) {
            $q = is_numeric($qty) ? (float)$qty : 0.0;
            if ($q > 0) { $moves[(int)$poi_id] = $q; }
        }
        if (!$moves) { po_redirect($from_list, $po_id, 'nothing'); }

        $conn->begin_transaction();
        try {
            $by     = $_SESSION['username'] ?? null;
            $po_num = $po_row['po_number'];

            foreach ($moves as $poi_id => $qty) {
                // A missing 'seen' field must never look like a valid claim.
                // -1 can never equal a stored qty_received, so the line is
                // refused rather than blindly topped up.
                $was = isset($seen[$poi_id]) && is_numeric($seen[$poi_id])
                     ? (float)$seen[$poi_id] : -1.0;

                // Someone received against this line between the page render
                // and this POST — a double-click, a back-button re-POST, or a
                // second clerk. Abandon the whole delivery rather than apply
                // part of it.
                if (!po_receive_line($conn, $po_id, (int)$poi_id, $was, $qty, $by, $po_num)) {
                    $conn->rollback();
                    po_redirect($from_list, $po_id, 'stale');
                }
            }

            // Status is derived from the lines, never assigned, so the badge
            // cannot drift from the quantities under it.
            $new = po_status_from_lines($conn, $po_id);
            $st  = $conn->prepare("UPDATE purchase_orders
                                   SET status = ?,
                                       received_at = CASE WHEN ? = 'Received' THEN NOW() ELSE received_at END
                                   WHERE po_id = ?");
            $st->bind_param('ssi', $new, $new, $po_id);
            $st->execute();

            $conn->commit();
            po_redirect($from_list, $po_id, $new === 'Received' ? 'received' : 'partial');
        } catch (Throwable $e) {
            $conn->rollback();
            po_redirect($from_list, $po_id, 'error');
        }
    }

    if ($action === 'close_short') {
        // Writing off undelivered goods is a commercial decision, so it is
        // gated on the role and not on the purchase_orders permission the
        // clerk already holds. Checked here rather than only in the markup —
        // hiding a button is not access control.
        if (!po_may_close_short($_SESSION['role'] ?? null)) {
            po_redirect($from_list, $po_id, 'denied');
        }
        // No stock is added. This records that the remainder is never coming,
        // which is the opposite of a delivery.
        $by   = $_SESSION['username'] ?? null;
        $stmt = $conn->prepare("UPDATE purchase_orders
                                SET status='Received', closed_short=1,
                                    closed_short_at=NOW(), closed_short_by=?,
                                    received_at=COALESCE(received_at, NOW())
                                WHERE po_id=? AND status='Partially Received'");
        $stmt->bind_param('si', $by, $po_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) { po_redirect($from_list, $po_id, 'nochange'); }
        po_redirect($from_list, $po_id, 'shortclosed');
    }

    if ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE purchase_orders SET status='Cancelled' WHERE po_id=? AND status IN ('Draft','Ordered')");
        $stmt->bind_param('i', $po_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) po_redirect($from_list, $po_id, 'nochange');
        po_redirect($from_list, $po_id, 'cancelled');
    }
}

// ── FETCH PO ──
$stmt = $conn->prepare("
    SELECT p.*, s.name AS supplier_name, s.contact_person, s.phone, s.email, s.address
    FROM purchase_orders p
    JOIN suppliers s ON s.supplier_id = p.supplier_id
    WHERE p.po_id = ?
");
$stmt->bind_param('i', $po_id);
$stmt->execute();
$po = $stmt->get_result()->fetch_assoc();
if (!$po) { header('Location: purchase_orders.php'); exit; }

$is_manager = po_may_close_short($_SESSION['role'] ?? null);

// ── FETCH ITEMS ──
$istmt = $conn->prepare("
    SELECT poi.*, i.ingredient_name, i.unit,
           poi.qty_ordered * poi.unit_cost AS line_total
    FROM purchase_order_items poi
    JOIN ingredients i ON i.ingredient_id = poi.ingredient_id
    WHERE poi.po_id = ?
    ORDER BY i.ingredient_name ASC
");
$istmt->bind_param('i', $po_id);
$istmt->execute();
$items = $istmt->get_result()->fetch_all(MYSQLI_ASSOC);

$msg_text = match($_GET['msg'] ?? '') {
    'ordered'   => ['text'=>'Status updated to Ordered.',    'type'=>'info'],
    'received'  => ['text'=>'Stock updated — marked Received!','type'=>'success'],
    'cancelled' => ['text'=>'Purchase order cancelled.',      'type'=>'danger'],
    'nochange'  => ['text'=>'No change — this order was already updated.','type'=>'info'],
    'badtoken'  => ['text'=>'Session expired. Reload and try again.','type'=>'danger'],
    'partial'   => ['text'=>'Delivery recorded. Some items are still outstanding.', 'type'=>'info'],
    'stale'     => ['text'=>'This purchase order changed while you were looking at it. Reload and try again.', 'type'=>'danger'],
    'nothing'   => ['text'=>'Nothing was recorded — every quantity was zero.',      'type'=>'info'],
    'badqty'    => ['text'=>'A received quantity cannot be negative.',              'type'=>'danger'],
    'error'     => ['text'=>'The delivery could not be saved. Nothing was changed.','type'=>'danger'],
    'shortclosed' => ['text'=>'Purchase order closed short. The outstanding items were written off.', 'type'=>'info'],
    'denied'    => ['text'=>'You do not have permission to close a purchase order short.', 'type'=>'danger'],
    default     => null,
};
if ($created_msg) $msg_text = ['text'=>$created_msg, 'type'=>'success'];

$statusColors = [
    'Draft'     => ['bg'=>'rgba(136,136,136,.18)',   'color'=>'#888',     'icon'=>'fa-pen'],
    'Ordered'   => ['bg'=>'rgba(52,152,219,.15)',   'color'=>'#3498db',  'icon'=>'fa-clock'],
    // Amber, not red: a part delivery needs attention, it is not a failure.
    'Partially Received' => ['bg'=>'rgba(224,169,85,.14)', 'color'=>'#e0a955', 'icon'=>'fa-truck-ramp-box'],
    'Received'  => ['bg'=>'rgba(85,224,135,.13)',   'color'=>'#55e087',  'icon'=>'fa-check-circle'],
    'Cancelled' => ['bg'=>'rgba(255,95,95,.12)',    'color'=>'#ff5f5f',  'icon'=>'fa-xmark-circle'],
];
$sc = $statusColors[$po['status']] ?? $statusColors['Draft'];

function he($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function fmtDate($d){ return $d ? date('M j, Y', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= he($po['po_number']) ?> | Bird's Nest Coffee</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script>(function(){var t=localStorage.getItem('theme');if(t==='light')document.documentElement.setAttribute('data-theme','light');})();</script>
<style>
:root{
    --bg:#0b0b0b; --bg-card:#131313; --bg-input:#1a1a1a;
    --border:#222; --border-hover:#333;
    --accent:#d1904b; --accent-light:#e8b87a;
    --text:#f5f5f5; --text-muted:#888; --text-light:#fff;
    --success:#55e087; --danger:#ff5f5f; --info:#3498db;
    --shadow-lg:0 8px 40px rgba(0,0,0,.55);
    --shadow-accent:0 4px 20px rgba(209,144,75,.18);
    --radius:14px; --transition:all .22s cubic-bezier(.4,0,.2,1);
    --surface:rgba(255,255,255,.02); --pill:rgba(255,255,255,.07); --btn-soft:rgba(255,255,255,.06);
}

[data-theme="light"]{
    --bg:#f5f0eb; --bg-card:#ffffff; --bg-input:#f0e9e0;
    --border:#e8ddd2; --border-hover:#d4c4b0;
    --text:#1a1008; --text-muted:#7a6a58; --text-light:#1a1008;
    --shadow-lg:0 8px 40px rgba(0,0,0,.12);
    --surface:rgba(0,0,0,.03); --pill:rgba(0,0,0,.06); --btn-soft:rgba(0,0,0,.05);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* TOP BAR */
.topbar{display:flex;align-items:center;gap:12px;padding:18px 32px;border-bottom:1px solid var(--border);background:var(--bg-card);position:sticky;top:0;z-index:100;}
.topbar-back{display:flex;align-items:center;gap:7px;color:#d1904b;text-decoration:none;font-size:13px;font-weight:600;padding:7px 14px;border-radius:10px;border:1px solid rgba(209,144,75,.35);background:rgba(209,144,75,.08);transition:var(--transition);}
.topbar-back:hover{background:rgba(209,144,75,.16);border-color:#d1904b;}
.topbar-title{font-size:18px;font-weight:700;color:var(--text-light);flex:1;}
.topbar-po{color:var(--accent);}
.btn{display:inline-flex;align-items:center;gap:7px;padding:8px 18px;border-radius:9px;border:none;font-family:'Poppins',sans-serif;font-size:13px;font-weight:600;cursor:pointer;transition:var(--transition);text-decoration:none;}
.btn-accent{background:linear-gradient(135deg,var(--accent),var(--accent-light));color:#000;}
.btn-accent:hover{transform:translateY(-2px);box-shadow:var(--shadow-accent);filter:brightness(1.08);}
.btn-outline{background:transparent;color:var(--text-muted);border:1px solid var(--border);}
.btn-outline:hover{color:var(--accent);border-color:var(--accent);}
.btn-success{background:rgba(85,224,135,.15);color:var(--success);border:1px solid rgba(85,224,135,.25);}
.btn-success:hover{background:rgba(85,224,135,.25);}
.btn-danger{background:rgba(255,95,95,.12);color:var(--danger);border:1px solid rgba(255,95,95,.2);}
.btn-danger:hover{background:rgba(255,95,95,.22);}
.btn-info{background:rgba(52,152,219,.13);color:var(--info);border:1px solid rgba(52,152,219,.22);}
.btn-info:hover{background:rgba(52,152,219,.22);}
.btn-print{background:var(--btn-soft);color:var(--text-muted);border:1px solid var(--border);}
.btn-print:hover{color:var(--text);border-color:var(--border-hover);}

.content{max-width:860px;margin:0 auto;padding:32px 24px;}

.alert{padding:12px 18px;border-radius:10px;margin-bottom:20px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;animation:fadeUp .35s ease both;}
.alert-success{background:rgba(85,224,135,.1);color:var(--success);border:1px solid rgba(85,224,135,.2);}
.alert-info{background:rgba(52,152,219,.1);color:var(--info);border:1px solid rgba(52,152,219,.2);}
.alert-danger{background:rgba(255,95,95,.1);color:var(--danger);border:1px solid rgba(255,95,95,.2);}

/* PO HEADER CARD */
.po-header-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:28px;margin-bottom:20px;animation:fadeUp .4s ease both;}
.po-header-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.po-number{font-size:24px;font-weight:800;color:var(--text-light);}
.po-meta{font-size:12px;color:var(--text-muted);margin-top:4px;}
.po-meta span{margin-right:16px;}
.po-meta i{margin-right:4px;}
.status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;}

.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-top:24px;padding-top:20px;border-top:1px solid var(--border);}
.info-block{}
.info-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.info-val{font-size:13px;color:var(--text);line-height:1.6;}
.info-val strong{color:var(--text-light);}

/* ITEMS CARD */
.items-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;animation:fadeUp .45s .1s ease both;}
.items-card-header{padding:18px 22px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;color:var(--text-light);display:flex;align-items:center;gap:8px;}
.items-card-header i{color:var(--accent);}

table{width:100%;border-collapse:collapse;}
thead th{padding:11px 18px;text-align:left;font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--border);background:var(--surface);}
tbody td{padding:14px 18px;border-bottom:1px solid var(--border);font-size:13px;vertical-align:middle;}
tbody tr:last-child td{border-bottom:none;}
tbody tr:hover td{background:var(--surface);}

.ing-name{font-weight:600;color:var(--text-light);}
.unit-pill{display:inline-block;background:var(--pill);border-radius:5px;padding:2px 8px;font-size:11px;color:var(--text-muted);}

.total-bar{display:flex;align-items:center;justify-content:flex-end;padding:16px 22px;border-top:2px solid var(--border);background:var(--surface);}
.total-bar-inner{text-align:right;}
.total-bar-label{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;}
.total-bar-value{font-size:26px;font-weight:800;color:var(--accent);}

.notes-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-top:20px;animation:fadeUp .45s .2s ease both;}
.notes-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;display:flex;align-items:center;gap:6px;}
.notes-text{font-size:13px;color:var(--text);line-height:1.6;}

/* ── PRINT STYLES ── */
@media print {
    body{background:#fff !important;color:#111 !important;}
    .topbar,.actions-bar,.no-print,.alert{display:none !important;}
    .content{max-width:100%;padding:0;}
    .po-header-card,.items-card,.notes-card{
        background:#fff !important;border:1px solid #ccc !important;
        box-shadow:none !important;border-radius:4px !important;
        page-break-inside:avoid;
    }
    .po-number{color:#111 !important;}
    .status-badge{border:1px solid #ccc !important;background:#f5f5f5 !important;color:#111 !important;}
    .info-val,.info-val strong,.ing-name,tbody td,.notes-text{color:#111 !important;}
    .unit-pill{background:#eee !important;color:#555 !important;}
    .total-bar-value{color:#333 !important;}
    .total-bar{border-top:2px solid #333 !important;}
    thead th{color:#555 !important;border-bottom:1px solid #ccc !important;}
    tbody tr:hover td{background:none !important;}

    .print-header{display:block !important;}
}

.print-header{
    display:none;
    text-align:center;padding-bottom:20px;margin-bottom:20px;border-bottom:2px solid #ccc;
}
.print-header h1{font-size:22px;font-weight:800;color:#111;}
.print-header p{font-size:13px;color:#555;}

@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:600px){
    .topbar{padding:14px 16px;flex-wrap:wrap;}
    .content{padding:16px 12px;}
    .info-grid{grid-template-columns:1fr;}
    thead th:nth-child(3),tbody td:nth-child(3){display:none;}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
    <a class="topbar-back" href="suppliers.php"><i class="fa-solid fa-truck-ramp-box"></i> Suppliers</a>
    <a class="topbar-back" href="purchase_orders.php"><i class="fa-solid fa-file-invoice"></i> Purchase Orders</a>
    <div class="topbar-title">
        <span class="topbar-po"><?= he($po['po_number']) ?></span>
    </div>
    <div class="actions-bar" style="display:flex;gap:8px;flex-wrap:wrap;">
        <button class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
        <?php if ($po['status'] === 'Draft'): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Mark this order as Placed/Ordered?')">
            <input type="hidden" name="action" value="mark_ordered">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn-info"><i class="fa-solid fa-paper-plane"></i> Mark Ordered</button>
        </form>
        <?php endif; ?>
        <?php if (in_array($po['status'], ['Draft','Ordered'])): ?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Cancel this purchase order?')">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-xmark"></i> Cancel PO</button>
        </form>
        <?php endif; ?>
        <?php if ($po['status'] === 'Partially Received' && $is_manager): ?>
        <form method="POST" style="display:inline"
              onsubmit="return confirm('Close this order short? The outstanding items will be written off and no stock will be added.')">
            <input type="hidden" name="action" value="close_short">
            <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-file-circle-xmark"></i> Close short</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="content">
    <?php if ($msg_text): ?>
    <div class="alert alert-<?= $msg_text['type'] ?>">
        <i class="fa-solid fa-<?= $msg_text['type']==='success'?'check-circle':($msg_text['type']==='danger'?'circle-exclamation':'info-circle') ?>"></i>
        <?= he($msg_text['text']) ?>
    </div>
    <?php endif; ?>

    <!-- Print header (only visible when printing) -->
    <div class="print-header">
        <h1>Bird's Nest Coffee</h1>
        <p>Purchase Order Receipt</p>
    </div>

    <!-- PO HEADER CARD -->
    <div class="po-header-card">
        <div class="po-header-top">
            <div>
                <div class="po-number"><?= he($po['po_number']) ?></div>
                <div class="po-meta">
                    <span><i class="fa-solid fa-calendar"></i> Created <?= fmtDate($po['created_at']) ?></span>
                    <?php if ($po['ordered_at']): ?>
                    <span><i class="fa-solid fa-paper-plane"></i> Ordered <?= fmtDate($po['ordered_at']) ?></span>
                    <?php endif; ?>
                    <?php if ($po['received_at']): ?>
                    <span><i class="fa-solid fa-check"></i> Received <?= fmtDate($po['received_at']) ?></span>
                    <?php endif; ?>
                    <?php if ($po['closed_short']): ?>
                    <span style="color:var(--warning,#e0a955)">
                        <i class="fa-solid fa-file-circle-xmark"></i>
                        Closed short <?= fmtDate($po['closed_short_at']) ?> by <?= he($po['closed_short_by']) ?>
                    </span>
                    <?php endif; ?>
                    <span><i class="fa-solid fa-user"></i> <?= he($po['created_by']) ?></span>
                </div>
            </div>
            <span class="status-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                <i class="fa-solid <?= $sc['icon'] ?>"></i> <?= $po['status'] ?>
            </span>
        </div>

        <div class="info-grid">
            <div class="info-block">
                <div class="info-label"><i class="fa-solid fa-truck-ramp-box"></i> Supplier</div>
                <div class="info-val">
                    <strong><?= he($po['supplier_name']) ?></strong><br>
                    <?php if ($po['contact_person']): ?>👤 <?= he($po['contact_person']) ?><br><?php endif; ?>
                    <?php if ($po['phone']): ?>📞 <?= he($po['phone']) ?><br><?php endif; ?>
                    <?php if ($po['email']): ?>✉️ <?= he($po['email']) ?><br><?php endif; ?>
                    <?php if ($po['address']): ?>📍 <?= he($po['address']) ?><?php endif; ?>
                </div>
            </div>
            <div class="info-block">
                <div class="info-label"><i class="fa-solid fa-dollar-sign"></i> Cost Summary</div>
                <div class="info-val">
                    <strong style="font-size:20px;color:var(--accent)">$<?= number_format($po['total_cost'],2) ?></strong>
                    <span style="color:var(--text-muted);font-size:12px">ordered</span><br>
                    <?php $vals = po_line_values($conn, $po_id);
                          /* total_cost is the order that was placed and is never
                             rewritten. The delivered value is derived, so a short
                             delivery stays distinguishable from a small order. */
                          if ($po['status'] === 'Partially Received' || $po['closed_short']): ?>
                    <span style="font-size:13px">Received <strong>$<?= number_format($vals['received'],2) ?></strong></span>
                    <span style="color:var(--warning,#e0a955);font-size:12px">
                        · $<?= number_format($vals['outstanding'],2) ?> outstanding
                    </span><br>
                    <?php endif; ?>
                    <span style="color:var(--text-muted);font-size:12px"><?= count($items) ?> ingredient<?= count($items)!=1?'s':'' ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ITEMS -->
    <?php
      // The form renders for exactly two statuses. Draft has not been placed;
      // Received and Cancelled are finished. Task 3's handler repeats this
      // check rather than trusting the markup.
      $canReceive = in_array($po['status'], ['Ordered', 'Partially Received'], true);
    ?>
    <div class="items-card">
        <div class="items-card-header"><i class="fa-solid fa-boxes-stacked"></i> Order Items</div>
        <?php if ($canReceive): ?>
        <form method="POST" id="receiveForm">
        <input type="hidden" name="action" value="receive_items">
        <input type="hidden" name="csrf_token" value="<?= he($_SESSION['csrf_token']) ?>">
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Ingredient</th>
                    <th>Unit</th>
                    <th style="text-align:right">Ordered</th>
                    <th style="text-align:right">Received</th>
                    <?php if ($canReceive): ?><th style="text-align:right">Receiving now</th><?php endif; ?>
                    <th style="text-align:right">Unit Cost</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $i => $item):
                $ordered     = (float)$item['qty_ordered'];
                $received    = (float)$item['qty_received'];
                $outstanding = max(0, $ordered - $received);
                $over        = $received > $ordered;
            ?>
            <tr>
                <td style="color:var(--text-muted);font-size:12px"><?= $i+1 ?></td>
                <td><span class="ing-name"><?= he($item['ingredient_name']) ?></span></td>
                <td><span class="unit-pill"><?= he($item['unit']) ?></span></td>
                <td style="text-align:right;font-weight:600"><?= number_format($ordered,3) ?></td>
                <td style="text-align:right;font-weight:600<?= $over ? ';color:var(--warning,#e0a955)' : '' ?>">
                    <?= number_format($received,3) ?>
                    <?php if ($over): ?><br><span style="font-size:11px">+<?= number_format($received-$ordered,3) ?> over</span><?php endif; ?>
                </td>
                <?php if ($canReceive): ?>
                <td style="text-align:right">
                    <?php if ($outstanding > 0): ?>
                        <?php /* Prefilled with the outstanding quantity: a full
                                 delivery is the normal case and stays one click,
                                 so typing is reserved for the exception. */ ?>
                        <input type="number" step="0.001" min="0"
                               name="recv[<?= (int)$item['poi_id'] ?>]"
                               value="<?= number_format($outstanding, 3, '.', '') ?>"
                               style="width:96px;text-align:right;padding:6px 8px;border-radius:6px;
                                      border:1px solid var(--border,#333);background:var(--card,#1a1a1a);
                                      color:inherit;">
                        <?php /* The value the form was rendered against. Task 3
                                 claims the line on this, so a replayed POST is
                                 refused instead of adding stock twice. */ ?>
                        <input type="hidden" name="seen[<?= (int)$item['poi_id'] ?>]"
                               value="<?= number_format($received, 3, '.', '') ?>">
                        <div style="font-size:11px;color:var(--text-muted);margin-top:3px">
                            <?= number_format($outstanding,3) ?> outstanding
                        </div>
                    <?php else: ?>
                        <span style="color:var(--success);font-size:12px">
                            <i class="fa-solid fa-check"></i> complete
                        </span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td style="text-align:right;color:var(--text-muted)">$<?= number_format($item['unit_cost'],2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($canReceive): ?>
        <div class="total-bar">
            <div class="total-bar-inner" style="gap:12px">
                <div class="total-bar-label">Record what actually arrived</div>
                <button type="submit" class="btn btn-success">
                    <i class="fa-solid fa-truck-ramp-box"></i> Confirm delivery
                </button>
            </div>
        </div>
        </form>
        <?php endif; ?>
        <div class="total-bar">
            <div class="total-bar-inner">
                <div class="total-bar-label">Total Cost (ordered)</div>
                <div class="total-bar-value">$<?= number_format($po['total_cost'],2) ?></div>
            </div>
        </div>
    </div>

    <?php if ($po['notes']): ?>
    <div class="notes-card">
        <div class="notes-label"><i class="fa-solid fa-note-sticky"></i> Notes</div>
        <div class="notes-text"><?= nl2br(he($po['notes'])) ?></div>
    </div>
    <?php endif; ?>
</div>

<script>
// follows shared theme key (toggled elsewhere)
window.addEventListener('storage', function (e) {
    if (e.key === 'theme') {
        if (e.newValue === 'light') document.documentElement.setAttribute('data-theme', 'light');
        else document.documentElement.removeAttribute('data-theme');
    }
});
</script>

</body>
</html>
